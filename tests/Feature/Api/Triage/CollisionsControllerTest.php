<?php

namespace Tests\Feature\Api\Triage;

use App\Enums\Permission;
use App\Models\CliToken;
use App\Models\Group;
use App\Models\TriageSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollisionsControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeTeamUser(): array
    {
        $user      = User::factory()->create(['tier' => 'team', 'permissions' => Permission::team()]);
        $plaintext = 'tl_' . bin2hex(random_bytes(10));
        CliToken::create([
            'user_id'    => $user->id,
            'name'       => 'CLI Token',
            'token_hash' => CliToken::hashToken($plaintext),
        ]);
        return [$user, $plaintext];
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
            'user_id'      => $user->id,
            'profile'      => 'production',
            'tickets'      => [],
            'git_branches' => $gitBranches,
            'ticket_count' => 0,
            'captured_at'  => $capturedAt ?? now()->toIsoString(),
        ]);
    }

    private function branches(string $branchName, array $files = [], array $tickets = []): array
    {
        return [['branch' => $branchName, 'base' => 'origin/main', 'tickets' => $tickets, 'files' => $files]];
    }

    // ── Auth ─────────────────────────────────────────────────────────────────

    public function test_missing_token_returns_401(): void
    {
        $this->getJson('/v1/triage/collisions')->assertStatus(401);
    }

    public function test_invalid_token_returns_401(): void
    {
        $this->withToken('bad-token')->getJson('/v1/triage/collisions')->assertStatus(401);
    }

    // ── Group / team membership ──────────────────────────────────────────────

    public function test_user_with_no_group_returns_empty_collisions(): void
    {
        [, $token] = $this->makeTeamUser();

        $response = $this->withToken($token)->getJson('/v1/triage/collisions');

        $response->assertStatus(200);
        $response->assertJson(['collisions' => []]);
        $this->assertStringContainsString('group', strtolower($response->json('message')));
    }

    public function test_user_alone_in_group_returns_empty_collisions(): void
    {
        [$user, $token] = $this->makeTeamUser();
        $this->addToGroup($user);

        $response = $this->withToken($token)->getJson('/v1/triage/collisions');

        $response->assertStatus(200);
        $response->assertJson(['collisions' => []]);
        $this->assertStringContainsString('teammate', strtolower($response->json('message')));
    }

    // ── Snapshot presence ────────────────────────────────────────────────────

    public function test_user_has_no_git_branches_snapshot_returns_empty_collisions(): void
    {
        [$user, $token] = $this->makeTeamUser();
        [$teammate]     = $this->makeTeamUser();
        $this->addToGroup($user, $teammate);
        $this->makeSnapshot($user, null);

        $response = $this->withToken($token)->getJson('/v1/triage/collisions');

        $response->assertStatus(200);
        $response->assertJson(['collisions' => []]);
        $this->assertStringContainsString('branch data', strtolower($response->json('message')));
    }

    public function test_stale_user_snapshot_older_than_7_days_ignored(): void
    {
        [$user, $token] = $this->makeTeamUser();
        [$teammate]     = $this->makeTeamUser();
        $this->addToGroup($user, $teammate);
        $this->makeSnapshot($user, $this->branches('feat/old', ['src/a.php']), now()->subDays(8)->toIsoString());

        $response = $this->withToken($token)->getJson('/v1/triage/collisions');

        $response->assertStatus(200);
        $response->assertJson(['collisions' => []]);
    }

    public function test_teammate_has_no_git_branches_snapshot_returns_empty_collisions(): void
    {
        [$user, $token] = $this->makeTeamUser();
        [$teammate]     = $this->makeTeamUser();
        $this->addToGroup($user, $teammate);
        $this->makeSnapshot($user, $this->branches('feat/mine', ['src/a.php']));
        $this->makeSnapshot($teammate, null);

        $this->withToken($token)->getJson('/v1/triage/collisions')
            ->assertStatus(200)
            ->assertJson(['collisions' => []]);
    }

    public function test_stale_teammate_snapshot_older_than_7_days_ignored(): void
    {
        [$user, $token] = $this->makeTeamUser();
        [$teammate]     = $this->makeTeamUser();
        $this->addToGroup($user, $teammate);
        $this->makeSnapshot($user, $this->branches('feat/mine', ['src/a.php']));
        $this->makeSnapshot($teammate, $this->branches('feat/theirs', ['src/a.php']), now()->subDays(8)->toIsoString());

        $this->withToken($token)->getJson('/v1/triage/collisions')
            ->assertStatus(200)
            ->assertJson(['collisions' => []]);
    }

    // ── Collision detection ──────────────────────────────────────────────────

    public function test_no_collision_when_files_do_not_overlap(): void
    {
        [$user, $token] = $this->makeTeamUser();
        [$teammate]     = $this->makeTeamUser();
        $this->addToGroup($user, $teammate);
        $this->makeSnapshot($user, $this->branches('feat/mine', ['src/a.php']));
        $this->makeSnapshot($teammate, $this->branches('feat/theirs', ['src/b.php']));

        $this->withToken($token)->getJson('/v1/triage/collisions')
            ->assertStatus(200)
            ->assertJson(['collisions' => []]);
    }

    public function test_returns_collision_when_files_overlap(): void
    {
        [$user, $token] = $this->makeTeamUser();
        [$teammate]     = $this->makeTeamUser();
        $this->addToGroup($user, $teammate);
        $this->makeSnapshot($user, $this->branches('feat/mine', ['src/a.php', 'src/b.php'], ['PROJ-1']));
        $this->makeSnapshot($teammate, $this->branches('feat/theirs', ['src/b.php', 'src/c.php'], ['PROJ-2']));

        $response    = $this->withToken($token)->getJson('/v1/triage/collisions');
        $collisions  = $response->json('collisions');

        $response->assertStatus(200);
        $this->assertCount(1, $collisions);
        $this->assertSame('feat/mine', $collisions[0]['your_branch']);
        $this->assertSame('feat/theirs', $collisions[0]['their_branch']);
        $this->assertSame(['src/b.php'], $collisions[0]['shared_files']);
        $this->assertSame(['PROJ-1'], $collisions[0]['your_tickets']);
        $this->assertSame(['PROJ-2'], $collisions[0]['their_tickets']);
    }

    public function test_collision_includes_teammate_name(): void
    {
        [$user, $token] = $this->makeTeamUser();
        $teammate       = User::factory()->create(['name' => 'Jane Dev', 'tier' => 'team', 'permissions' => Permission::team()]);
        $this->addToGroup($user, $teammate);
        $this->makeSnapshot($user, $this->branches('feat/mine', ['src/shared.php']));
        $this->makeSnapshot($teammate, $this->branches('feat/theirs', ['src/shared.php']));

        $response = $this->withToken($token)->getJson('/v1/triage/collisions');
        $this->assertSame('Jane Dev', $response->json('collisions.0.teammate'));
    }

    public function test_returns_collisions_from_multiple_teammates(): void
    {
        [$user, $token] = $this->makeTeamUser();
        [$teammateA]    = $this->makeTeamUser();
        [$teammateB]    = $this->makeTeamUser();
        $this->addToGroup($user, $teammateA, $teammateB);
        $this->makeSnapshot($user, $this->branches('feat/mine', ['src/shared.php']));
        $this->makeSnapshot($teammateA, $this->branches('feat/a', ['src/shared.php']));
        $this->makeSnapshot($teammateB, $this->branches('feat/b', ['src/shared.php']));

        $this->assertCount(2, $this->withToken($token)->getJson('/v1/triage/collisions')->json('collisions'));
    }

    public function test_only_most_recent_teammate_snapshot_used(): void
    {
        [$user, $token] = $this->makeTeamUser();
        [$teammate]     = $this->makeTeamUser();
        $this->addToGroup($user, $teammate);
        $this->makeSnapshot($user, $this->branches('feat/mine', ['src/a.php']));
        // Old snapshot has overlap; recent snapshot does not
        $this->makeSnapshot($teammate, $this->branches('feat/old', ['src/a.php']), now()->subDays(2)->toIsoString());
        $this->makeSnapshot($teammate, $this->branches('feat/new', ['src/b.php']), now()->toIsoString());

        $this->withToken($token)->getJson('/v1/triage/collisions')
            ->assertStatus(200)
            ->assertJson(['collisions' => []]);
    }

    public function test_multiple_branch_pairs_all_reported(): void
    {
        [$user, $token] = $this->makeTeamUser();
        [$teammate]     = $this->makeTeamUser();
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

        $this->assertCount(2, $this->withToken($token)->getJson('/v1/triage/collisions')->json('collisions'));
    }

    public function test_branches_capped_at_20_per_snapshot(): void
    {
        [$user, $token] = $this->makeTeamUser();
        [$teammate]     = $this->makeTeamUser();
        $this->addToGroup($user, $teammate);

        // 25 branches for me; teammate only overlaps on branches 21-25 (beyond cap)
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

        $this->withToken($token)->getJson('/v1/triage/collisions')
            ->assertStatus(200)
            ->assertJson(['collisions' => []]);
    }
}
