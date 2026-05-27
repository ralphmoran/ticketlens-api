<?php

namespace Tests\Feature\Api\Triage;

use App\Enums\Permission;
use App\Jobs\EvaluateAlertsJob;
use App\Models\CliToken;
use App\Models\Group;
use App\Models\TriageSnapshot;
use App\Models\User;
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
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeUserWithToken(string $tier = 'team'): array
    {
        $permissions = match ($tier) {
            'team', 'enterprise' => Permission::team(),
            'pro'                => Permission::pro(),
            default              => Permission::free(),
        };
        $user      = User::factory()->create(['tier' => $tier, 'permissions' => $permissions]);
        $plaintext = 'tl_' . str_repeat('a', 40);
        CliToken::create([
            'user_id'    => $user->id,
            'name'       => 'CLI Token',
            'token_hash' => CliToken::hashToken($plaintext),
        ]);
        return [$user, $plaintext];
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

    // ── Auth ─────────────────────────────────────────────────────────────────

    public function test_missing_token_returns_401(): void
    {
        $this->postJson('/v1/triage/push', $this->validPayload())->assertStatus(401);
    }

    public function test_invalid_token_returns_401(): void
    {
        $this->withToken('bad-token')->postJson('/v1/triage/push', $this->validPayload())->assertStatus(401);
    }

    // ── Permissions ───────────────────────────────────────────────────────────

    public function test_free_tier_user_returns_403(): void
    {
        [, $token] = $this->makeUserWithToken('free');

        $this->withToken($token)->postJson('/v1/triage/push', $this->validPayload())->assertStatus(403);
        $this->assertSame(0, TriageSnapshot::count());
    }

    public function test_pro_tier_user_returns_403(): void
    {
        // Pro tier lacks AttentionQueue (512) — push is a Team-only feature
        [, $token] = $this->makeUserWithToken('pro');

        $this->withToken($token)->postJson('/v1/triage/push', $this->validPayload())->assertStatus(403);
        $this->assertSame(0, TriageSnapshot::count());
    }

    // ── Push behaviour ────────────────────────────────────────────────────────

    public function test_valid_push_stores_snapshot(): void
    {
        [$user, $token] = $this->makeUserWithToken();

        $response = $this->withToken($token)->postJson('/v1/triage/push', $this->validPayload());

        $response->assertStatus(200);
        $response->assertJson(['pushed' => true, 'ticket_count' => 1]);
        $this->assertDatabaseHas('triage_snapshots', [
            'user_id'      => $user->id,
            'profile'      => 'production',
            'ticket_count' => 1,
        ]);
    }

    public function test_second_push_upserts_not_duplicates(): void
    {
        [, $token] = $this->makeUserWithToken();
        $this->withToken($token)->postJson('/v1/triage/push', $this->validPayload());

        $updated = $this->validPayload(['tickets' => [
            ['key' => 'PROJ-456', 'summary' => 'New ticket', 'status' => 'In Progress',
             'assignee' => 'Jane', 'attention_score' => 3.0, 'flags' => [],
             'compliance_coverage' => null, 'compliance_status' => 'unknown',
             'url' => 'https://jira.example.com/browse/PROJ-456', 'last_updated' => '2026-05-11T08:00:00Z'],
        ]]);
        $this->withToken($token)->postJson('/v1/triage/push', $updated);

        $this->assertSame(1, TriageSnapshot::count());
        $this->assertSame(1, TriageSnapshot::first()->ticket_count);
    }

    public function test_different_profiles_stored_separately(): void
    {
        [, $token] = $this->makeUserWithToken();
        $this->withToken($token)->postJson('/v1/triage/push', $this->validPayload(['profile' => 'staging']));
        $this->withToken($token)->postJson('/v1/triage/push', $this->validPayload(['profile' => 'production']));

        $this->assertSame(2, TriageSnapshot::count());
    }

    public function test_snapshot_linked_to_user(): void
    {
        [$user, $token] = $this->makeUserWithToken();

        $this->withToken($token)->postJson('/v1/triage/push', $this->validPayload());

        $this->assertSame($user->id, TriageSnapshot::first()->user_id);
    }

    // ── Validation ────────────────────────────────────────────────────────────

    public function test_missing_profile_returns_422(): void
    {
        [, $token] = $this->makeUserWithToken();
        $payload = $this->validPayload();
        unset($payload['profile']);

        $this->withToken($token)->postJson('/v1/triage/push', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['profile']);
    }

    public function test_missing_tickets_returns_422(): void
    {
        [, $token] = $this->makeUserWithToken();
        $payload = $this->validPayload();
        unset($payload['tickets']);

        $this->withToken($token)->postJson('/v1/triage/push', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tickets']);
    }

    // ── Job dispatch ──────────────────────────────────────────────────────────

    public function test_evaluate_alerts_job_dispatched_on_successful_push(): void
    {
        [$user, $token] = $this->makeUserWithToken();

        $this->withToken($token)->postJson('/v1/triage/push', $this->validPayload());

        Queue::assertPushed(EvaluateAlertsJob::class, fn ($job) => $job->getUserId() === $user->id);
    }

    public function test_evaluate_alerts_job_not_dispatched_on_403(): void
    {
        [, $token] = $this->makeUserWithToken('free');

        $this->withToken($token)->postJson('/v1/triage/push', $this->validPayload());

        Queue::assertNotPushed(EvaluateAlertsJob::class);
    }

    // ── git_branches ──────────────────────────────────────────────────────────

    public function test_push_stores_snapshot_without_git_branches(): void
    {
        [, $token] = $this->makeUserWithToken();

        $this->withToken($token)->postJson('/v1/triage/push', $this->validPayload())
            ->assertStatus(200)
            ->assertJson(['pushed' => true]);
        $this->assertNull(TriageSnapshot::first()->git_branches);
    }

    public function test_push_stores_git_branches_when_provided(): void
    {
        [, $token] = $this->makeUserWithToken();
        $branches  = [
            ['branch' => 'feat/PROJ-123', 'base' => 'origin/main', 'tickets' => ['PROJ-123'], 'files' => ['src/a.js']],
        ];

        $this->withToken($token)->postJson('/v1/triage/push', $this->validPayload(['git_branches' => $branches]))
            ->assertStatus(200);

        $this->assertEquals($branches, TriageSnapshot::first()->git_branches);
    }

    public function test_push_accepts_null_git_branches(): void
    {
        [, $token] = $this->makeUserWithToken();

        $this->withToken($token)->postJson('/v1/triage/push', $this->validPayload(['git_branches' => null]))
            ->assertStatus(200)
            ->assertJson(['pushed' => true]);
    }

    public function test_push_rejects_git_branches_with_missing_branch_name(): void
    {
        [, $token] = $this->makeUserWithToken();
        $invalid   = [['base' => 'origin/main', 'tickets' => [], 'files' => []]];

        $this->withToken($token)->postJson('/v1/triage/push', $this->validPayload(['git_branches' => $invalid]))
            ->assertStatus(422);
    }

    public function test_push_upsert_updates_git_branches(): void
    {
        [, $token] = $this->makeUserWithToken();
        $first  = [['branch' => 'feat/old', 'base' => 'origin/main', 'tickets' => [], 'files' => ['a.js']]];
        $second = [['branch' => 'feat/new', 'base' => 'origin/main', 'tickets' => [], 'files' => ['b.js']]];

        $this->withToken($token)->postJson('/v1/triage/push', $this->validPayload(['git_branches' => $first]));
        $this->withToken($token)->postJson('/v1/triage/push', $this->validPayload(['git_branches' => $second]));

        $snapshot = TriageSnapshot::first();
        $this->assertEquals('feat/new', $snapshot->git_branches[0]['branch']);
        $this->assertCount(1, TriageSnapshot::all());
    }
}
