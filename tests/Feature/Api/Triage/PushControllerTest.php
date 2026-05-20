<?php

namespace Tests\Feature\Api\Triage;

use App\Enums\Permission;
use App\Jobs\EvaluateAlertsJob;
use App\Models\Group;
use App\Models\License;
use App\Models\TriageSnapshot;
use App\Models\User;
use App\Services\LicenseValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PushControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->mock(LicenseValidationService::class, fn ($m) => $m->shouldReceive('isValid')->andReturn(true));
    }

    private function makeUserWithLicense(string $key = 'valid-key', string $tier = 'team', array $licenseOverrides = []): User
    {
        $permissions = match ($tier) {
            'team', 'enterprise' => Permission::team(),
            'pro'                => Permission::pro(),
            default              => Permission::free(),
        };
        $user = User::factory()->create(['tier' => $tier, 'permissions' => $permissions]);
        License::create(array_merge([
            'user_id'         => $user->id,
            'lemon_key_hash'  => hash('sha256', $key),
            'status'          => 'active',
            'tier'            => $tier,
            'seats'           => 1,
        ], $licenseOverrides));
        return $user;
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'profile'     => 'production',
            'captured_at' => '2026-05-11T10:00:00Z',
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

    public function test_valid_push_stores_snapshot(): void
    {
        $this->makeUserWithLicense();

        $response = $this->withToken('valid-key')
            ->postJson('/v1/triage/push', $this->validPayload());

        $response->assertStatus(200);
        $response->assertJson(['pushed' => true, 'ticket_count' => 1]);
        $this->assertDatabaseHas('triage_snapshots', [
            'license_key_hash' => hash('sha256', 'valid-key'),
            'profile'          => 'production',
            'ticket_count'     => 1,
        ]);
    }

    public function test_second_push_upserts_not_duplicates(): void
    {
        $this->makeUserWithLicense();
        $this->withToken('valid-key')->postJson('/v1/triage/push', $this->validPayload());

        $updatedPayload = $this->validPayload(['tickets' => [
            ['key' => 'PROJ-456', 'summary' => 'New ticket', 'status' => 'In Progress',
             'assignee' => 'Jane', 'attention_score' => 3.0, 'flags' => [],
             'compliance_coverage' => null, 'compliance_status' => 'unknown',
             'url' => 'https://jira.example.com/browse/PROJ-456', 'last_updated' => '2026-05-11T08:00:00Z'],
        ]]);
        $this->withToken('valid-key')->postJson('/v1/triage/push', $updatedPayload);

        $this->assertSame(1, TriageSnapshot::count());
        $this->assertSame(1, TriageSnapshot::first()->ticket_count);
    }

    public function test_different_profiles_stored_separately(): void
    {
        $this->makeUserWithLicense();
        $this->withToken('valid-key')->postJson('/v1/triage/push', $this->validPayload(['profile' => 'staging']));
        $this->withToken('valid-key')->postJson('/v1/triage/push', $this->validPayload(['profile' => 'production']));

        $this->assertSame(2, TriageSnapshot::count());
    }

    public function test_missing_token_returns_401(): void
    {
        $response = $this->postJson('/v1/triage/push', $this->validPayload());
        $response->assertStatus(401);
    }

    public function test_invalid_token_returns_401(): void
    {
        $this->mock(LicenseValidationService::class, fn ($m) => $m->shouldReceive('isValid')->andReturn(false));

        $response = $this->withToken('bad-key')->postJson('/v1/triage/push', $this->validPayload());
        $response->assertStatus(401);
    }

    public function test_missing_profile_returns_422(): void
    {
        $this->makeUserWithLicense();
        $payload = $this->validPayload();
        unset($payload['profile']);

        $response = $this->withToken('valid-key')->postJson('/v1/triage/push', $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['profile']);
    }

    public function test_missing_tickets_returns_422(): void
    {
        $this->makeUserWithLicense();
        $payload = $this->validPayload();
        unset($payload['tickets']);

        $response = $this->withToken('valid-key')->postJson('/v1/triage/push', $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['tickets']);
    }

    public function test_snapshot_linked_to_user_when_license_found(): void
    {
        $user = $this->makeUserWithLicense();

        $this->withToken('valid-key')->postJson('/v1/triage/push', $this->validPayload());

        $this->assertSame($user->id, TriageSnapshot::first()->user_id);
    }

    public function test_snapshot_stored_without_user_when_no_license_in_db(): void
    {
        // License is valid (LemonSqueezy external) but not in local DB.
        $this->withToken('external-key')->postJson('/v1/triage/push', $this->validPayload());

        $this->assertSame(1, TriageSnapshot::count());
        $this->assertNull(TriageSnapshot::first()->user_id);
    }

    public function test_push_from_user_without_attention_queue_permission_returns_403(): void
    {
        // Free-tier user: valid license key but no AttentionQueue bit.
        $this->makeUserWithLicense('free-key', 'free');

        $response = $this->withToken('free-key')->postJson('/v1/triage/push', $this->validPayload());

        $response->assertStatus(403);
        $this->assertSame(0, TriageSnapshot::count());
    }

    public function test_expired_license_stores_snapshot_without_user_id(): void
    {
        // License exists but is expired — user_id must not be resolved.
        $this->makeUserWithLicense('expired-key', 'team', ['expires_at' => now()->subDay()]);

        $response = $this->withToken('expired-key')->postJson('/v1/triage/push', $this->validPayload());

        $response->assertStatus(200);
        $this->assertNull(TriageSnapshot::first()->user_id);
    }

    // ── Job dispatch (lock tests for PushController → EvaluateAlertsJob contract) ──

    public function test_evaluate_alerts_job_dispatched_when_user_resolved(): void
    {
        $user = $this->makeUserWithLicense();

        $this->withToken('valid-key')->postJson('/v1/triage/push', $this->validPayload());

        Queue::assertPushed(EvaluateAlertsJob::class, fn ($job) =>
            $job->getUserId() === $user->id
        );
    }

    public function test_evaluate_alerts_job_not_dispatched_when_no_user_resolved(): void
    {
        // External license — no local user_id
        $this->withToken('external-key')->postJson('/v1/triage/push', $this->validPayload());

        Queue::assertNotPushed(EvaluateAlertsJob::class);
    }

    public function test_evaluate_alerts_job_not_dispatched_on_403(): void
    {
        $this->makeUserWithLicense('free-key', 'free');

        $this->withToken('free-key')->postJson('/v1/triage/push', $this->validPayload());

        Queue::assertNotPushed(EvaluateAlertsJob::class);
    }

    // ── LOCK: git_branches behaviour ─────────────────────────────────────────

    public function test_push_stores_snapshot_without_git_branches(): void
    {
        $this->makeUserWithLicense();

        $response = $this->withToken('valid-key')
            ->postJson('/v1/triage/push', $this->validPayload());

        $response->assertStatus(200);
        $response->assertJson(['pushed' => true]);
        $this->assertDatabaseHas('triage_snapshots', [
            'license_key_hash' => hash('sha256', 'valid-key'),
            'git_branches'     => null,
        ]);
    }

    // ── git_branches: new behaviour ──────────────────────────────────────────

    public function test_push_stores_git_branches_when_provided(): void
    {
        $this->makeUserWithLicense();
        $branches = [
            ['branch' => 'feat/PROJ-123', 'base' => 'origin/main', 'tickets' => ['PROJ-123'], 'files' => ['src/a.js']],
        ];

        $response = $this->withToken('valid-key')
            ->postJson('/v1/triage/push', $this->validPayload(['git_branches' => $branches]));

        $response->assertStatus(200);
        $this->assertDatabaseHas('triage_snapshots', [
            'license_key_hash' => hash('sha256', 'valid-key'),
        ]);
        $snapshot = \App\Models\TriageSnapshot::where('license_key_hash', hash('sha256', 'valid-key'))->first();
        $this->assertEquals($branches, $snapshot->git_branches);
    }

    public function test_push_accepts_null_git_branches(): void
    {
        $this->makeUserWithLicense();

        $response = $this->withToken('valid-key')
            ->postJson('/v1/triage/push', $this->validPayload(['git_branches' => null]));

        $response->assertStatus(200);
        $response->assertJson(['pushed' => true]);
    }

    public function test_push_rejects_git_branches_with_missing_branch_name(): void
    {
        $this->makeUserWithLicense();
        $invalid = [['base' => 'origin/main', 'tickets' => [], 'files' => []]];

        $response = $this->withToken('valid-key')
            ->postJson('/v1/triage/push', $this->validPayload(['git_branches' => $invalid]));

        $response->assertStatus(422);
    }

    public function test_push_upsert_updates_git_branches(): void
    {
        $this->makeUserWithLicense();
        $first = [['branch' => 'feat/old', 'base' => 'origin/main', 'tickets' => [], 'files' => ['a.js']]];
        $second = [['branch' => 'feat/new', 'base' => 'origin/main', 'tickets' => [], 'files' => ['b.js']]];

        $this->withToken('valid-key')->postJson('/v1/triage/push', $this->validPayload(['git_branches' => $first]));
        $this->withToken('valid-key')->postJson('/v1/triage/push', $this->validPayload(['git_branches' => $second]));

        $snapshot = \App\Models\TriageSnapshot::where('license_key_hash', hash('sha256', 'valid-key'))->first();
        $this->assertEquals('feat/new', $snapshot->git_branches[0]['branch']);
        $this->assertCount(1, \App\Models\TriageSnapshot::all());
    }
}
