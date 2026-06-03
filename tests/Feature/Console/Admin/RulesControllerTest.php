<?php

namespace Tests\Feature\Console\Admin;

use App\Models\Group;
use App\Models\TriageSnapshot;
use App\Models\User;
use App\Models\WorkflowRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RulesControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeManager(): User
    {
        // team(2687) | teamManagerMask(384) = 3071 — must include TeamManageMembers(128)
        // so EnsureTeamManager middleware passes
        $user  = User::factory()->create(['tier' => 'team', 'permissions' => 3071]);
        $group = Group::create(['name' => "Team {$user->id}", 'owner_id' => $user->id]);
        $group->members()->attach($user->id);
        return $user;
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    public function test_index_requires_auth(): void
    {
        $this->get('/console/admin/rules')->assertRedirect('/console/login');
    }

    public function test_index_blocks_non_manager_via_middleware(): void
    {
        // EnsureTeamManager redirects non-managers to /console/dashboard — not a 403.
        // Free, Pro, and rank-and-file team users all hit this redirect.
        $user  = User::factory()->create(['tier' => 'free', 'permissions' => 64]);
        $group = Group::create(['name' => 'Free Group', 'owner_id' => $user->id]);
        $group->members()->attach($user->id);

        $this->actingAs($user)->get('/console/admin/rules')
            ->assertRedirect('/console/dashboard');
    }

    public function test_index_renders_for_team_manager(): void
    {
        $user = $this->makeManager();

        $this->actingAs($user)->get('/console/admin/rules')->assertOk();
    }

    public function test_index_returns_null_stale_rule_when_none_configured(): void
    {
        $user = $this->makeManager();

        $response = $this->actingAs($user)->get('/console/admin/rules');
        $response->assertOk()
            ->assertInertia(fn ($page) => $page->where('stale_rule', null));
    }

    public function test_index_returns_existing_stale_rule(): void
    {
        $user  = $this->makeManager();
        $group = $user->ownedGroup;

        WorkflowRule::create([
            'group_id' => $group->id,
            'type'     => 'stale',
            'config'   => ['stale_days' => 14, 'statuses' => ['In Review', 'In Progress']],
            'enabled'  => true,
        ]);

        $response = $this->actingAs($user)->get('/console/admin/rules');
        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stale_rule.enabled', true)
                ->where('stale_rule.config.stale_days', 14)
            );
    }

    public function test_index_includes_known_statuses_from_snapshots(): void
    {
        $user  = $this->makeManager();
        $group = $user->ownedGroup;

        TriageSnapshot::create([
            'user_id'          => $user->id,
            'license_key_hash' => hash('sha256', 'key'),
            'profile'          => 'work',
            'tickets'          => [
                ['key' => 'X-1', 'status' => 'In Review'],
                ['key' => 'X-2', 'status' => 'Done'],
            ],
            'ticket_count' => 2,
            'captured_at'  => now(),
        ]);

        $this->actingAs($user)->get('/console/admin/rules')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('known_statuses'));
    }

    // ── Save stale rule ───────────────────────────────────────────────────────

    public function test_save_stale_creates_rule(): void
    {
        $user  = $this->makeManager();
        $group = $user->ownedGroup;

        $this->actingAs($user)->post('/console/admin/rules/stale', [
            'enabled'    => true,
            'stale_days' => 14,
            'statuses'   => ['In Review', 'In Progress'],
        ])->assertRedirect();

        $this->assertDatabaseHas('workflow_rules', [
            'group_id' => $group->id,
            'type'     => 'stale',
            'enabled'  => true,
        ]);
    }

    public function test_save_stale_updates_existing_rule(): void
    {
        $user  = $this->makeManager();
        $group = $user->ownedGroup;

        WorkflowRule::create([
            'group_id' => $group->id,
            'type'     => 'stale',
            'config'   => ['stale_days' => 7, 'statuses' => ['In Review']],
            'enabled'  => true,
        ]);

        $this->actingAs($user)->post('/console/admin/rules/stale', [
            'enabled'    => false,
            'stale_days' => 21,
            'statuses'   => ['Code Review'],
        ])->assertRedirect();

        $this->assertEquals(1, WorkflowRule::where('group_id', $group->id)->count());
        $rule = WorkflowRule::where('group_id', $group->id)->first();
        $this->assertFalse($rule->enabled);
        $this->assertEquals(21, $rule->config['stale_days']);
    }

    public function test_save_stale_validates_stale_days_min(): void
    {
        $user = $this->makeManager();

        $this->actingAs($user)->post('/console/admin/rules/stale', [
            'enabled'    => true,
            'stale_days' => 0,
            'statuses'   => ['In Review'],
        ])->assertSessionHasErrors('stale_days');
    }

    public function test_save_stale_validates_statuses_required(): void
    {
        $user = $this->makeManager();

        $this->actingAs($user)->post('/console/admin/rules/stale', [
            'enabled'    => true,
            'stale_days' => 7,
            'statuses'   => [],
        ])->assertSessionHasErrors('statuses');
    }

    public function test_save_stale_blocks_non_manager_via_middleware(): void
    {
        // EnsureTeamManager redirects — free users and non-managers don't reach the controller.
        $user  = User::factory()->create(['tier' => 'free', 'permissions' => 64]);
        $group = Group::create(['name' => 'Free', 'owner_id' => $user->id]);
        $group->members()->attach($user->id);

        $this->actingAs($user)->post('/console/admin/rules/stale', [
            'enabled' => true, 'stale_days' => 7, 'statuses' => ['In Review'],
        ])->assertRedirect('/console/dashboard');
    }

    // ── Destroy stale rule ────────────────────────────────────────────────────

    public function test_destroy_stale_blocks_non_manager_via_middleware(): void
    {
        // EnsureTeamManager redirects before the controller's tier check runs.
        $user  = User::factory()->create(['tier' => 'free', 'permissions' => 64]);
        $group = Group::create(['name' => 'Free', 'owner_id' => $user->id]);
        $group->members()->attach($user->id);

        $this->actingAs($user)->delete('/console/admin/rules/stale')
            ->assertRedirect('/console/dashboard');
    }

    public function test_non_manager_member_cannot_save_stale_rule(): void
    {
        $manager = $this->makeManager();
        // Plain team member — has team permissions but no TeamManageMembers bit or ownedGroup
        $member  = User::factory()->create(['tier' => 'team', 'permissions' => 2687]);
        $manager->ownedGroup->members()->attach($member->id);

        // EnsureTeamManager: member has no ownedGroup → middleware blocks with redirect
        $this->actingAs($member)->post('/console/admin/rules/stale', [
            'enabled' => true, 'stale_days' => 7, 'statuses' => ['In Review'],
        ])->assertRedirect('/console/dashboard');
    }

    public function test_non_manager_member_cannot_delete_stale_rule(): void
    {
        $manager = $this->makeManager();
        $member  = User::factory()->create(['tier' => 'team', 'permissions' => 2687]);
        $manager->ownedGroup->members()->attach($member->id);

        WorkflowRule::create([
            'group_id' => $manager->ownedGroup->id,
            'type'     => 'stale',
            'config'   => ['stale_days' => 7, 'statuses' => ['In Review']],
            'enabled'  => true,
        ]);

        $this->actingAs($member)->delete('/console/admin/rules/stale')
            ->assertRedirect('/console/dashboard');

        $this->assertDatabaseHas('workflow_rules', [
            'group_id' => $manager->ownedGroup->id,
            'type'     => 'stale',
        ]);
    }

    public function test_destroy_stale_deletes_rule(): void
    {
        $user  = $this->makeManager();
        $group = $user->ownedGroup;

        WorkflowRule::create([
            'group_id' => $group->id,
            'type'     => 'stale',
            'config'   => ['stale_days' => 7, 'statuses' => ['In Review']],
            'enabled'  => true,
        ]);

        $this->actingAs($user)->delete('/console/admin/rules/stale')->assertRedirect();

        $this->assertDatabaseMissing('workflow_rules', ['group_id' => $group->id, 'type' => 'stale']);
    }
}
