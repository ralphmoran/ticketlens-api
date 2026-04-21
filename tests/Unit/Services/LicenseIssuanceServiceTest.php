<?php

namespace Tests\Unit\Services;

use App\Mail\LicenseIssuedMail;
use App\Models\License;
use App\Models\User;
use App\Services\AuditService;
use App\Services\LicenseIssuanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LicenseIssuanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private LicenseIssuanceService $service;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->service = new LicenseIssuanceService(new AuditService);
        $this->owner   = User::factory()->create(['is_owner' => true]);
    }

    // --- Raw-key security invariants ---

    public function test_raw_key_is_returned_to_caller(): void
    {
        $recipient = User::factory()->create();

        $result = $this->service->issue($this->owner, $recipient, 'pro');

        $this->assertArrayHasKey('raw_key', $result);
        $this->assertNotEmpty($result['raw_key']);
    }

    public function test_raw_key_is_never_persisted_to_licenses_table(): void
    {
        $recipient = User::factory()->create();

        $result = $this->service->issue($this->owner, $recipient, 'pro');
        $rawKey = $result['raw_key'];

        // The raw key must not appear in any column of the licenses table.
        $license = License::first();
        $this->assertNotEquals($rawKey, $license->lemon_key_hash);
        foreach ($license->toArray() as $column => $value) {
            $this->assertNotEquals($rawKey, $value, "Raw key leaked into licenses.{$column}");
        }
    }

    public function test_raw_key_is_never_written_to_application_logs(): void
    {
        // Capture every log message emitted during issuance.
        $captured = [];
        Log::listen(function ($level, $message, $context) use (&$captured) {
            $captured[] = ['level' => $level, 'message' => $message, 'context' => $context];
        });

        $recipient = User::factory()->create();
        $result    = $this->service->issue($this->owner, $recipient, 'pro');
        $rawKey    = $result['raw_key'];

        foreach ($captured as $entry) {
            $serialized = $entry['message'] . ' ' . json_encode($entry['context']);
            $this->assertStringNotContainsString($rawKey, $serialized, "Raw key leaked into {$entry['level']} log");
        }
        // Sentinel — issuance succeeded; raw key stayed out of whatever logs existed.
        $this->assertNotEmpty($rawKey);
    }

    public function test_raw_key_is_never_stored_in_audit_log(): void
    {
        $recipient = User::factory()->create();

        $result = $this->service->issue($this->owner, $recipient, 'pro');
        $rawKey = $result['raw_key'];

        $this->assertDatabaseMissing('audit_logs', ['old_value' => $rawKey]);
        $this->assertDatabaseMissing('audit_logs', ['new_value' => $rawKey]);
        // Scan every row's serialized metadata for the raw key.
        foreach (\App\Models\AuditLog::all() as $log) {
            $serialized = json_encode($log->toArray());
            $this->assertStringNotContainsString($rawKey, $serialized, 'Raw key leaked into audit log');
        }
    }

    // --- Key format + entropy ---

    public function test_key_has_TL_prefix_and_uuid_v4_format(): void
    {
        $recipient = User::factory()->create();

        $result = $this->service->issue($this->owner, $recipient, 'pro');

        $this->assertMatchesRegularExpression(
            '/^TL-[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $result['raw_key'],
        );
    }

    public function test_generated_keys_are_unique_across_many_issuances(): void
    {
        $recipients = User::factory()->count(100)->create();
        $seen = [];

        foreach ($recipients as $recipient) {
            $result = $this->service->issue($this->owner, $recipient, 'pro', sendEmail: false);
            $this->assertArrayNotHasKey($result['raw_key'], $seen, 'Duplicate key generated');
            $seen[$result['raw_key']] = true;
        }

        $this->assertCount(100, $seen);
    }

    // --- Hash compatibility with webhook ---

    public function test_hash_algorithm_matches_lemonsqueezy_webhook(): void
    {
        $recipient = User::factory()->create();

        $result = $this->service->issue($this->owner, $recipient, 'pro');

        $expected = hash('sha256', $result['raw_key']);
        $this->assertSame($expected, $result['license']->lemon_key_hash);
    }

    // --- Issued-by / seats / expiry ---

    public function test_license_records_issuing_owner_id(): void
    {
        $recipient = User::factory()->create();

        $result = $this->service->issue($this->owner, $recipient, 'pro');

        $this->assertSame($this->owner->id, $result['license']->issued_by_user_id);
    }

    public function test_default_seats_per_tier(): void
    {
        $client1 = User::factory()->create();
        $client2 = User::factory()->create();
        $client3 = User::factory()->create();

        $this->assertSame(1,  $this->service->issue($this->owner, $client1, 'pro')['license']->seats);
        $this->assertSame(5,  $this->service->issue($this->owner, $client2, 'team')['license']->seats);
        $this->assertSame(25, $this->service->issue($this->owner, $client3, 'enterprise')['license']->seats);
    }

    public function test_explicit_seats_override_default(): void
    {
        $recipient = User::factory()->create();

        $result = $this->service->issue($this->owner, $recipient, 'team', seats: 12);

        $this->assertSame(12, $result['license']->seats);
    }

    public function test_expiry_is_stored_when_provided(): void
    {
        $recipient = User::factory()->create();
        $expiry    = now()->addDays(30);

        $result = $this->service->issue($this->owner, $recipient, 'pro', expiresAt: $expiry);

        $this->assertEqualsWithDelta($expiry->timestamp, $result['license']->expires_at->timestamp, 2);
    }

    public function test_expiry_null_means_permanent(): void
    {
        $recipient = User::factory()->create();

        $result = $this->service->issue($this->owner, $recipient, 'pro');

        $this->assertNull($result['license']->expires_at);
    }

    // --- Email dispatch ---

    public function test_email_dispatched_when_send_email_true(): void
    {
        $recipient = User::factory()->create(['email' => 'customer@example.com']);

        $result = $this->service->issue($this->owner, $recipient, 'pro', sendEmail: true);
        $rawKey = $result['raw_key'];

        Mail::assertQueued(LicenseIssuedMail::class, fn ($mail) =>
            $mail->hasTo('customer@example.com') && $mail->rawKey === $rawKey
        );
    }

    public function test_email_not_dispatched_when_send_email_false(): void
    {
        $recipient = User::factory()->create();

        $this->service->issue($this->owner, $recipient, 'pro', sendEmail: false);

        Mail::assertNothingQueued();
    }

    // --- Tier validation ---

    public function test_free_tier_cannot_be_issued(): void
    {
        $recipient = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->service->issue($this->owner, $recipient, 'free');
    }

    public function test_invalid_tier_rejected(): void
    {
        $recipient = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->service->issue($this->owner, $recipient, 'platinum');
    }

    // --- Audit log ---

    public function test_issuance_writes_audit_log_with_hash_tail_only(): void
    {
        $recipient = User::factory()->create();

        $result = $this->service->issue($this->owner, $recipient, 'team');

        $log = \App\Models\AuditLog::where('action', 'license.issued')->firstOrFail();
        $this->assertSame($this->owner->id, $log->actor_id);
        $this->assertSame($recipient->id, $log->target_user_id);
        $this->assertSame(substr($result['license']->lemon_key_hash, -8), $log->metadata['hash_tail']);
        $this->assertSame('team', $log->metadata['tier']);
    }

    // --- Revoke ---

    public function test_revoke_marks_license_cancelled_without_deleting(): void
    {
        $recipient = User::factory()->create();
        $license   = $this->service->issue($this->owner, $recipient, 'pro')['license'];

        $this->service->revoke($this->owner, $license);

        $this->assertSame('cancelled', $license->fresh()->status);
        $this->assertDatabaseHas('licenses', ['id' => $license->id]);
    }

    public function test_revoke_is_idempotent(): void
    {
        $recipient = User::factory()->create();
        $license   = $this->service->issue($this->owner, $recipient, 'pro')['license'];

        $this->service->revoke($this->owner, $license);
        $this->service->revoke($this->owner, $license->fresh()); // second call must be a no-op

        // Exactly one revocation audit entry — not two.
        $this->assertSame(1, \App\Models\AuditLog::where('action', 'license.revoked')->count());
    }

    public function test_revoke_writes_audit_log(): void
    {
        $recipient = User::factory()->create();
        $license   = $this->service->issue($this->owner, $recipient, 'pro')['license'];

        $this->service->revoke($this->owner, $license);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id'       => $this->owner->id,
            'target_user_id' => $recipient->id,
            'action'         => 'license.revoked',
        ]);
    }
}
