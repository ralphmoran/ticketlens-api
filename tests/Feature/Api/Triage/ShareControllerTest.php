<?php

namespace Tests\Feature\Api\Triage;

use App\Enums\Permission;
use App\Models\License;
use App\Models\TriageSnapshot;
use App\Models\User;
use App\Services\LicenseValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShareControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(LicenseValidationService::class, fn ($m) => $m->shouldReceive('isValid')->andReturn(true));
    }

    // ── LOCK: existing invariants that must not change ──────────────────────

    public function test_lock_attention_queue_permission_value_is_512(): void
    {
        $this->assertSame(512, Permission::AttentionQueue->value);
    }

    public function test_lock_push_endpoint_still_reachable(): void
    {
        $this->makeUserWithLicense();

        $response = $this->withToken('valid-key')
            ->postJson('/v1/triage/push', $this->validPayload());

        $response->assertStatus(200);
        $response->assertJson(['pushed' => true]);
    }

    // ── RED/GREEN: share endpoint ────────────────────────────────────────────

    public function test_valid_share_stores_snapshot_and_returns_url(): void
    {
        $this->makeUserWithLicense();

        $response = $this->withToken('valid-key')
            ->postJson('/v1/triage/share', $this->validPayload());

        $response->assertStatus(200);
        $response->assertJsonStructure(['url', 'expires_at']);
        $this->assertStringContainsString('/s/', $response->json('url'));
    }

    public function test_share_url_contains_the_stored_token(): void
    {
        $this->makeUserWithLicense();

        $response = $this->withToken('valid-key')
            ->postJson('/v1/triage/share', $this->validPayload());

        $snapshot = TriageSnapshot::first();
        $this->assertNotNull($snapshot->share_token);
        $this->assertStringEndsWith($snapshot->share_token, $response->json('url'));
    }

    public function test_share_expires_in_approximately_24h(): void
    {
        $this->makeUserWithLicense();

        $this->withToken('valid-key')->postJson('/v1/triage/share', $this->validPayload());

        $snapshot = TriageSnapshot::first();
        $diff = $snapshot->share_expires_at->diffInMinutes(now()->addHours(24), absolute: true);
        $this->assertLessThan(2, $diff); // within 2 min of 24h from now
    }

    public function test_second_share_renews_token_and_upserts_snapshot(): void
    {
        $this->makeUserWithLicense();
        $this->withToken('valid-key')->postJson('/v1/triage/share', $this->validPayload());
        $firstToken = TriageSnapshot::first()->share_token;

        $this->withToken('valid-key')->postJson('/v1/triage/share', $this->validPayload());

        $this->assertSame(1, TriageSnapshot::count());
        $this->assertNotEquals($firstToken, TriageSnapshot::first()->share_token);
    }

    public function test_snapshot_linked_to_user_when_license_found(): void
    {
        $user = $this->makeUserWithLicense();

        $this->withToken('valid-key')->postJson('/v1/triage/share', $this->validPayload());

        $this->assertSame($user->id, TriageSnapshot::first()->user_id);
    }

    public function test_snapshot_stored_without_user_when_no_license_in_db(): void
    {
        $this->withToken('external-key')->postJson('/v1/triage/share', $this->validPayload());

        $this->assertSame(1, TriageSnapshot::count());
        $this->assertNull(TriageSnapshot::first()->user_id);
    }

    public function test_free_tier_user_returns_403_and_no_snapshot(): void
    {
        $this->makeUserWithLicense('free-key', 'free');

        $response = $this->withToken('free-key')->postJson('/v1/triage/share', $this->validPayload());

        $response->assertStatus(403);
        $this->assertSame(0, TriageSnapshot::count());
    }

    public function test_missing_token_returns_401(): void
    {
        $response = $this->postJson('/v1/triage/share', $this->validPayload());
        $response->assertStatus(401);
    }

    public function test_invalid_token_returns_401(): void
    {
        $this->mock(LicenseValidationService::class, fn ($m) => $m->shouldReceive('isValid')->andReturn(false));

        $response = $this->withToken('bad-key')->postJson('/v1/triage/share', $this->validPayload());
        $response->assertStatus(401);
    }

    public function test_missing_profile_returns_422(): void
    {
        $this->makeUserWithLicense();
        $payload = $this->validPayload();
        unset($payload['profile']);

        $response = $this->withToken('valid-key')->postJson('/v1/triage/share', $payload);
        $response->assertStatus(422);
    }

    public function test_push_and_share_can_coexist_as_separate_snapshots(): void
    {
        $this->makeUserWithLicense();

        $this->withToken('valid-key')->postJson('/v1/triage/push', $this->validPayload());
        $this->withToken('valid-key')->postJson('/v1/triage/share', $this->validPayload());

        // Same license_key_hash + profile → still one row (upsert), but now has share_token
        $this->assertSame(1, TriageSnapshot::count());
        $this->assertNotNull(TriageSnapshot::first()->share_token);
    }

    // ── Public share page ────────────────────────────────────────────────────

    public function test_public_page_renders_for_valid_token(): void
    {
        $snapshot = TriageSnapshot::create([
            'license_key_hash' => hash('sha256', 'any-key'),
            'profile'          => 'production',
            'tickets'          => [['key' => 'PROJ-1', 'summary' => 'Test ticket', 'flags' => []]],
            'ticket_count'     => 1,
            'captured_at'      => now(),
            'share_token'      => 'test-token-abc',
            'share_expires_at' => now()->addHours(24),
        ]);

        $response = $this->get('/s/test-token-abc');

        $response->assertStatus(200);
        $response->assertSee('PROJ-1');
        $response->assertSee('Test ticket');
    }

    public function test_public_page_returns_404_for_expired_token(): void
    {
        TriageSnapshot::create([
            'license_key_hash' => hash('sha256', 'any-key'),
            'profile'          => 'production',
            'tickets'          => [],
            'ticket_count'     => 0,
            'captured_at'      => now(),
            'share_token'      => 'expired-token',
            'share_expires_at' => now()->subHours(1),
        ]);

        $response = $this->get('/s/expired-token');
        $response->assertStatus(404);
    }

    public function test_public_page_returns_404_for_unknown_token(): void
    {
        $response = $this->get('/s/nonexistent-token-xyz');
        $response->assertStatus(404);
    }

    public function test_public_page_requires_no_authentication(): void
    {
        TriageSnapshot::create([
            'license_key_hash' => hash('sha256', 'any-key'),
            'profile'          => 'dev',
            'tickets'          => [],
            'ticket_count'     => 0,
            'captured_at'      => now(),
            'share_token'      => 'public-token',
            'share_expires_at' => now()->addHours(24),
        ]);

        // Not logged in — should still render
        $response = $this->get('/s/public-token');
        $response->assertStatus(200);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeUserWithLicense(string $key = 'valid-key', string $tier = 'team'): User
    {
        $permissions = match ($tier) {
            'team', 'enterprise' => Permission::team(),
            'pro'                => Permission::pro(),
            default              => Permission::free(),
        };
        $user = User::factory()->create(['tier' => $tier, 'permissions' => $permissions]);
        License::create([
            'user_id'        => $user->id,
            'lemon_key_hash' => hash('sha256', $key),
            'status'         => 'active',
            'tier'           => $tier,
            'seats'          => 1,
        ]);
        return $user;
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'profile'     => 'production',
            'captured_at' => '2026-05-20T10:00:00Z',
            'tickets'     => [
                [
                    'key'                 => 'PROJ-123',
                    'summary'             => 'Fix login page',
                    'status'              => 'Code Review',
                    'assignee'            => 'John Doe',
                    'attention_score'     => 8.5,
                    'flags'               => ['needs-response'],
                    'compliance_coverage' => null,
                    'compliance_status'   => 'unknown',
                    'url'                 => 'https://jira.example.com/browse/PROJ-123',
                    'last_updated'        => '2026-05-10T09:00:00Z',
                ],
            ],
        ], $overrides);
    }
}
