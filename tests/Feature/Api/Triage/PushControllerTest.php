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

        $snapshot = TriageSnapshot::orderByDesc('captured_at')->first();
        $this->assertEquals('feat/new', $snapshot->git_branches[0]['branch']);
        $this->assertCount(1, TriageSnapshot::all());
    }

    // ── History accumulation (daily-dedup insert semantics) ──────────────────

    public function test_push_on_different_day_creates_new_row(): void
    {
        [, $token] = $this->makeUserWithToken();

        $this->withToken($token)->postJson('/v1/triage/push', $this->validPayload([
            'captured_at' => '2026-05-10T10:00:00Z',
        ]));
        $this->withToken($token)->postJson('/v1/triage/push', $this->validPayload([
            'captured_at' => '2026-05-11T10:00:00Z',
        ]));

        $this->assertSame(2, TriageSnapshot::count());
    }

    public function test_push_same_day_updates_existing_row(): void
    {
        [, $token] = $this->makeUserWithToken();

        $this->withToken($token)->postJson('/v1/triage/push', $this->validPayload([
            'captured_at' => '2026-05-11T08:00:00Z',
        ]));
        $this->withToken($token)->postJson('/v1/triage/push', $this->validPayload([
            'captured_at' => '2026-05-11T18:00:00Z',
            'tickets'     => [
                ['key' => 'PROJ-999', 'summary' => 'Later push', 'status' => 'Done',
                 'assignee' => 'Eve', 'attention_score' => 1.0, 'flags' => [],
                 'compliance_coverage' => null, 'compliance_status' => 'unknown',
                 'url' => 'https://jira.example.com/browse/PROJ-999', 'last_updated' => '2026-05-11T17:00:00Z'],
            ],
        ]));

        $this->assertSame(1, TriageSnapshot::count());
        $this->assertSame(1, TriageSnapshot::first()->ticket_count);
    }

    public function test_old_rows_pruned_on_push(): void
    {
        [$user, $token] = $this->makeUserWithToken();

        // Seed an old row (91 days ago) directly
        TriageSnapshot::create([
            'user_id'      => $user->id,
            'profile'      => 'production',
            'tickets'      => [],
            'ticket_count' => 0,
            'captured_at'  => now()->subDays(91),
        ]);

        $this->assertSame(1, TriageSnapshot::count());

        // New push triggers pruning
        $this->withToken($token)->postJson('/v1/triage/push', $this->validPayload());

        // Old row gone, new row created
        $this->assertSame(1, TriageSnapshot::count());
        $this->assertSame(1, TriageSnapshot::first()->ticket_count);
    }

    public function test_push_accepts_last_comment_at_per_ticket(): void
    {
        [, $token] = $this->makeUserWithToken();
        $payload = $this->validPayload([
            'tickets' => [
                array_merge($this->validPayload()['tickets'][0], [
                    'last_comment_at' => '2026-05-11T09:30:00Z',
                ]),
            ],
        ]);

        $this->withToken($token)->postJson('/v1/triage/push', $payload)
            ->assertStatus(200);

        $ticket = TriageSnapshot::first()->tickets[0];
        $this->assertSame('2026-05-11T09:30:00Z', $ticket['last_comment_at']);
    }

    // ── LOCK: backward-compat — cli_activity is optional ─────────────────────

    public function test_lock_push_without_cli_activity_succeeds(): void
    {
        [, $token] = $this->makeUserWithToken();

        $this->withToken($token)->postJson('/v1/triage/push', $this->validPayload())
            ->assertStatus(200)
            ->assertJsonFragment(['pushed' => true]);

        $snapshot = TriageSnapshot::first();
        $this->assertNotNull($snapshot);
        $this->assertNull($snapshot->cli_activity);
    }

    // ── RED: cli_activity stored when sent ────────────────────────────────────

    public function test_cli_activity_is_stored_on_snapshot(): void
    {
        [, $token] = $this->makeUserWithToken();

        $payload = $this->validPayload([
            'cli_activity' => [
                'fetch_count'       => 12,
                'triage_run_count'  => 3,
                'invocations'       => 17,
            ],
        ]);

        $this->withToken($token)->postJson('/v1/triage/push', $payload)
            ->assertStatus(200);

        $activity = TriageSnapshot::first()->cli_activity;
        $this->assertSame(12, $activity['fetch_count']);
        $this->assertSame(3,  $activity['triage_run_count']);
        $this->assertSame(17, $activity['invocations']);
    }

    public function test_cli_activity_rejects_non_integer_fields(): void
    {
        [, $token] = $this->makeUserWithToken();

        $payload = $this->validPayload([
            'cli_activity' => ['fetch_count' => 'abc'],
        ]);

        $this->withToken($token)->postJson('/v1/triage/push', $payload)
            ->assertStatus(422);
    }

    public function test_cli_activity_rejects_negative_values(): void
    {
        [, $token] = $this->makeUserWithToken();

        $payload = $this->validPayload([
            'cli_activity' => ['fetch_count' => -1],
        ]);

        $this->withToken($token)->postJson('/v1/triage/push', $payload)
            ->assertStatus(422);
    }

    // ── LOCK: no commands → snapshot created, usage_logs untouched ───────────

    public function test_lock_push_without_commands_creates_snapshot_only(): void
    {
        [, $token] = $this->makeUserWithToken();

        $this->withToken($token)->postJson('/v1/triage/push', $this->validPayload())
            ->assertStatus(200);

        $this->assertSame(1, TriageSnapshot::count());
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('usage_logs')->count());
    }

    // ── RED: cli_activity.commands → rows written to usage_logs ──────────────

    public function test_push_with_command_activity_writes_usage_logs(): void
    {
        [$user, $token] = $this->makeUserWithToken();

        $payload = $this->validPayload([
            'cli_activity' => [
                'fetch_count'      => 5,
                'triage_run_count' => 2,
                'invocations'      => 10,
                'commands'         => [
                    'triage'     => ['count' => 2, '--push' => 2],
                    'compliance' => ['count' => 1],
                ],
            ],
        ]);

        $this->withToken($token)->postJson('/v1/triage/push', $payload)
            ->assertStatus(200);

        $this->assertSame(2, \Illuminate\Support\Facades\DB::table('usage_logs')->count());

        $triageLog = \Illuminate\Support\Facades\DB::table('usage_logs')
            ->where('user_id', $user->id)
            ->where('action', 'triage')
            ->first();

        $this->assertNotNull($triageLog);
        $metadata = json_decode($triageLog->metadata, true);
        $this->assertSame(2, $metadata['count']);
        $this->assertSame(2, $metadata['flags']['--push']);
    }
}
