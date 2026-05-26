<?php

namespace Tests\Feature\Api\Triage;

use App\Enums\Permission;
use App\Models\Group;
use App\Models\License;
use App\Models\TriageSnapshot;
use App\Models\User;
use App\Services\LicenseValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollisionsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(LicenseValidationService::class, fn ($m) => $m->shouldReceive('isValid')->andReturn(true));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeTeamUser(string $key = 'team-key'): User
    {
        $user = User::factory()->create(['tier' => 'team', 'permissions' => Permission::team()]);
        License::create([
            'user_id'        => $user->id,
            'lemon_key_hash' => hash('sha256', $key),
            'status'         => 'active',
            'tier'           => 'team',
            'seats'          => 5,
        ]);
        return $user;
    }

    private function addToGroup(User ...$users): Group
    {
        $group = Group::create(['name' => 'Test Team', 'owner_id' => $users[0]->id]);
        foreach ($users as $user) {
            $group->users()->attach($user->id);
        }
        return $group;
    }

    private function makeSnapshot(User $user, ?array $gitBranches = null, ?string $capturedAt = null): TriageSnapshot
    {
        return TriageSnapshot::create([
            'user_id'          => $user->id,
            'license_key_hash' => hash('sha256', uniqid('snap-' . $user->id . '-', true)),
            'profile'          => 'production',
            'tickets'          => [],
            'git_branches'     => $gitBranches,
            'ticket_count'     => 0,
            'captured_at'      => $capturedAt ?? now()->toIsoString(),
        ]);
    }

    private function branches(string $branchName, array $files = [], array $tickets = []): array
    {
        return [['branch' => $branchName, 'base' => 'origin/main', 'tickets' => $tickets, 'files' => $files]];
    }

    // ── Auth ─────────────────────────────────────────────────────────────────

    public function test_missing_token_returns_401(): void
    {
        $response = $this->getJson('/v1/triage/collisions');
        $response->assertStatus(401);
    }

    public function test_invalid_token_returns_401(): void
    {
        $this->mock(LicenseValidationService::class, fn ($m) => $m->shouldReceive('isValid')->andReturn(false));

        $response = $this->withToken('bad-key')->getJson('/v1/triage/collisions');
        $response->assertStatus(401);
    }

    // ── License resolution ───────────────────────────────────────────────────

    public function test_license_key_not_in_db_returns_empty_collisions(): void
    {
        // Key valid externally (mock) but no row in licenses table.
        $response = $this->withToken('external-key')->getJson('/v1/triage/collisions');

        $response->assertStatus(200);
        $response->assertJson(['collisions' => []]);
        $this->assertStringContainsString('not linked', $response->json('message'));
    }

    public function test_expired_license_returns_empty_collisions(): void
    {
        $user = User::factory()->create(['tier' => 'team', 'permissions' => Permission::team()]);
        License::create([
            'user_id'        => $user->id,
            'lemon_key_hash' => hash('sha256', 'expired-key'),
            'status'         => 'active',
            'tier'           => 'team',
            'seats'          => 5,
            'expires_at'     => now()->subDay(),
        ]);

        $response = $this->withToken('expired-key')->getJson('/v1/triage/collisions');

        $response->assertStatus(200);
        $response->assertJson(['collisions' => []]);
    }

    // ── Group / team membership ──────────────────────────────────────────────

    public function test_user_with_no_group_returns_empty_collisions(): void
    {
        $this->makeTeamUser();

        $response = $this->withToken('team-key')->getJson('/v1/triage/collisions');

        $response->assertStatus(200);
        $response->assertJson(['collisions' => []]);
        $this->assertStringContainsString('group', strtolower($response->json('message')));
    }

    public function test_user_alone_in_group_returns_empty_collisions(): void
    {
        $user = $this->makeTeamUser();
        $this->addToGroup($user);

        $response = $this->withToken('team-key')->getJson('/v1/triage/collisions');

        $response->assertStatus(200);
        $response->assertJson(['collisions' => []]);
        $this->assertStringContainsString('teammate', strtolower($response->json('message')));
    }

    // ── Snapshot presence ────────────────────────────────────────────────────

    public function test_user_has_no_git_branches_snapshot_returns_empty_collisions(): void
    {
        $user     = $this->makeTeamUser();
        $teammate = $this->makeTeamUser('teammate-key');
        $this->addToGroup($user, $teammate);

        // User has a snapshot but no git_branches
        $this->makeSnapshot($user, null);

        $response = $this->withToken('team-key')->getJson('/v1/triage/collisions');

        $response->assertStatus(200);
        $response->assertJson(['collisions' => []]);
        $this->assertStringContainsString('branch data', strtolower($response->json('message')));
    }

    public function test_stale_user_snapshot_older_than_7_days_ignored(): void
    {
        $user     = $this->makeTeamUser();
        $teammate = $this->makeTeamUser('teammate-key');
        $this->addToGroup($user, $teammate);

        $this->makeSnapshot($user, $this->branches('feat/old', ['src/a.php']), now()->subDays(8)->toIsoString());

        $response = $this->withToken('team-key')->getJson('/v1/triage/collisions');

        $response->assertStatus(200);
        $response->assertJson(['collisions' => []]);
    }

    public function test_teammate_has_no_git_branches_snapshot_returns_empty_collisions(): void
    {
        $user     = $this->makeTeamUser();
        $teammate = $this->makeTeamUser('teammate-key');
        $this->addToGroup($user, $teammate);

        $this->makeSnapshot($user, $this->branches('feat/mine', ['src/a.php']));
        $this->makeSnapshot($teammate, null); // no git_branches

        $response = $this->withToken('team-key')->getJson('/v1/triage/collisions');

        $response->assertStatus(200);
        $response->assertJson(['collisions' => []]);
    }

    public function test_stale_teammate_snapshot_older_than_7_days_ignored(): void
    {
        $user     = $this->makeTeamUser();
        $teammate = $this->makeTeamUser('teammate-key');
        $this->addToGroup($user, $teammate);

        $this->makeSnapshot($user, $this->branches('feat/mine', ['src/a.php']));
        $this->makeSnapshot($teammate, $this->branches('feat/theirs', ['src/a.php']), now()->subDays(8)->toIsoString());

        $response = $this->withToken('team-key')->getJson('/v1/triage/collisions');

        $response->assertStatus(200);
        $response->assertJson(['collisions' => []]);
    }

    // ── Collision detection ───────────────────────────────────────────────────

    public function test_no_collision_when_files_do_not_overlap(): void
    {
        $user     = $this->makeTeamUser();
        $teammate = $this->makeTeamUser('teammate-key');
        $this->addToGroup($user, $teammate);

        $this->makeSnapshot($user, $this->branches('feat/mine', ['src/a.php']));
        $this->makeSnapshot($teammate, $this->branches('feat/theirs', ['src/b.php']));

        $response = $this->withToken('team-key')->getJson('/v1/triage/collisions');

        $response->assertStatus(200);
        $response->assertJson(['collisions' => []]);
    }

    public function test_returns_collision_when_files_overlap(): void
    {
        $user     = $this->makeTeamUser();
        $teammate = $this->makeTeamUser('teammate-key');
        $this->addToGroup($user, $teammate);

        $this->makeSnapshot($user, $this->branches('feat/mine', ['src/a.php', 'src/b.php'], ['PROJ-1']));
        $this->makeSnapshot($teammate, $this->branches('feat/theirs', ['src/b.php', 'src/c.php'], ['PROJ-2']));

        $response = $this->withToken('team-key')->getJson('/v1/triage/collisions');

        $response->assertStatus(200);
        $collisions = $response->json('collisions');
        $this->assertCount(1, $collisions);
        $this->assertSame('feat/mine', $collisions[0]['your_branch']);
        $this->assertSame('feat/theirs', $collisions[0]['their_branch']);
        $this->assertSame(['src/b.php'], $collisions[0]['shared_files']);
        $this->assertSame(['PROJ-1'], $collisions[0]['your_tickets']);
        $this->assertSame(['PROJ-2'], $collisions[0]['their_tickets']);
    }

    public function test_collision_includes_teammate_name(): void
    {
        $user     = $this->makeTeamUser();
        $teammate = User::factory()->create(['name' => 'Jane Dev', 'tier' => 'team', 'permissions' => Permission::team()]);
        License::create([
            'user_id'        => $teammate->id,
            'lemon_key_hash' => hash('sha256', 'teammate-key'),
            'status'         => 'active',
            'tier'           => 'team',
            'seats'          => 5,
        ]);
        $this->addToGroup($user, $teammate);

        $this->makeSnapshot($user, $this->branches('feat/mine', ['src/shared.php']));
        $this->makeSnapshot($teammate, $this->branches('feat/theirs', ['src/shared.php']));

        $response = $this->withToken('team-key')->getJson('/v1/triage/collisions');

        $this->assertSame('Jane Dev', $response->json('collisions.0.teammate'));
    }

    public function test_returns_collisions_from_multiple_teammates(): void
    {
        $user      = $this->makeTeamUser();
        $teamateA  = $this->makeTeamUser('teammate-a-key');
        $teamateB  = $this->makeTeamUser('teammate-b-key');
        $this->addToGroup($user, $teamateA, $teamateB);

        $this->makeSnapshot($user, $this->branches('feat/mine', ['src/shared.php']));
        $this->makeSnapshot($teamateA, $this->branches('feat/a', ['src/shared.php']));
        $this->makeSnapshot($teamateB, $this->branches('feat/b', ['src/shared.php']));

        $response = $this->withToken('team-key')->getJson('/v1/triage/collisions');

        $this->assertCount(2, $response->json('collisions'));
    }

    public function test_only_most_recent_teammate_snapshot_used(): void
    {
        $user     = $this->makeTeamUser();
        $teammate = $this->makeTeamUser('teammate-key');
        $this->addToGroup($user, $teammate);

        $this->makeSnapshot($user, $this->branches('feat/mine', ['src/a.php']));

        // Old snapshot has overlap; recent snapshot does not
        $this->makeSnapshot($teammate, $this->branches('feat/old', ['src/a.php']), now()->subDays(2)->toIsoString());
        $this->makeSnapshot($teammate, $this->branches('feat/new', ['src/b.php']), now()->toIsoString());

        $response = $this->withToken('team-key')->getJson('/v1/triage/collisions');

        $response->assertStatus(200);
        $response->assertJson(['collisions' => []]);
    }

    public function test_multiple_branch_pairs_all_reported(): void
    {
        $user     = $this->makeTeamUser();
        $teammate = $this->makeTeamUser('teammate-key');
        $this->addToGroup($user, $teammate);

        $myBranches = [
            ['branch' => 'feat/feature-a', 'base' => 'origin/main', 'tickets' => [], 'files' => ['src/a.php']],
            ['branch' => 'feat/feature-b', 'base' => 'origin/main', 'tickets' => [], 'files' => ['src/b.php']],
        ];
        $theirBranches = [
            ['branch' => 'feat/their-a', 'base' => 'origin/main', 'tickets' => [], 'files' => ['src/a.php']],
            ['branch' => 'feat/their-b', 'base' => 'origin/main', 'tickets' => [], 'files' => ['src/b.php']],
        ];

        $this->makeSnapshot($user, $myBranches);
        $this->makeSnapshot($teammate, $theirBranches);

        $response = $this->withToken('team-key')->getJson('/v1/triage/collisions');

        $this->assertCount(2, $response->json('collisions'));
    }

    public function test_branches_capped_at_20_per_snapshot(): void
    {
        $user     = $this->makeTeamUser();
        $teammate = $this->makeTeamUser('teammate-key');
        $this->addToGroup($user, $teammate);

        // 25 branches for me, last 5 share files with teammate — but only first 20 are processed
        $myBranches = array_map(
            fn ($i) => ['branch' => "feat/mine-{$i}", 'base' => 'main', 'tickets' => [], 'files' => ["src/file-{$i}.php"]],
            range(1, 25),
        );
        $theirBranches = array_map(
            fn ($i) => ['branch' => "feat/theirs-{$i}", 'base' => 'main', 'tickets' => [], 'files' => ["src/file-{$i}.php"]],
            range(21, 25),
        );

        $this->makeSnapshot($user, $myBranches);
        $this->makeSnapshot($teammate, $theirBranches);

        $response = $this->withToken('team-key')->getJson('/v1/triage/collisions');

        $response->assertStatus(200);
        // Branches 21–25 are beyond the cap of 20, so no overlaps are detected
        $response->assertJson(['collisions' => []]);
    }
}
