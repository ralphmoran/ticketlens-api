<?php

namespace Tests\Feature\Console\Admin;

use App\Models\Group;
use App\Models\SlackIntegration;
use App\Models\TrackerProfile;
use App\Models\TriageSnapshot;
use App\Models\User;
use App\Models\WorkflowRule;
use App\Services\SseEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    private function connectSlack(Group $group): SlackIntegration
    {
        return SlackIntegration::create([
            'group_id'       => $group->id,
            'connected_by'   => $group->owner_id,
            'workspace_id'   => 'W123',
            'workspace_name' => 'Acme',
            'bot_token'      => 'xoxb-test',
            'channel_id'     => 'C001',
            'channel_name'   => 'triages',
        ]);
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

    public function test_index_returns_null_custom_rule_when_none_configured(): void
    {
        $user = $this->makeManager();

        $response = $this->actingAs($user)->get('/console/admin/rules');
        $response->assertOk()
            ->assertInertia(fn ($page) => $page->where('custom_rule', null));
    }

    public function test_index_returns_existing_custom_rule(): void
    {
        $user  = $this->makeManager();
        $group = $user->ownedGroup;

        WorkflowRule::create([
            'group_id' => $group->id,
            'type'     => 'custom',
            'config'   => ['rules' => [
                ['match' => ['priority' => 'Highest'], 'action' => 'force-urgent', 'reason' => 'P1 always urgent'],
            ]],
            'enabled'  => true,
        ]);

        $response = $this->actingAs($user)->get('/console/admin/rules');
        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('custom_rule.enabled', true)
                ->where('custom_rule.config.rules.0.action', 'force-urgent')
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

    public function test_index_includes_known_priorities_and_labels_from_snapshots(): void
    {
        $user = $this->makeManager();

        TriageSnapshot::create([
            'user_id'          => $user->id,
            'license_key_hash' => hash('sha256', 'key'),
            'profile'          => 'work',
            'tickets'          => [
                ['key' => 'X-1', 'priority' => 'Highest', 'labels' => ['backend', 'critical']],
                ['key' => 'X-2', 'priority' => 'Low',     'labels' => ['frontend']],
            ],
            'ticket_count' => 2,
            'captured_at'  => now(),
        ]);

        $this->actingAs($user)->get('/console/admin/rules')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('known_priorities', ['Highest', 'Low'])
                ->where('known_labels', ['backend', 'critical', 'frontend'])
            );
    }

    public function test_index_known_priorities_and_labels_empty_when_no_snapshots(): void
    {
        $user = $this->makeManager();

        $this->actingAs($user)->get('/console/admin/rules')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('known_priorities', [])
                ->where('known_labels', [])
            );
    }

    public function test_index_includes_slack_connected_true_when_integration_exists(): void
    {
        $user = $this->makeManager();
        $this->connectSlack($user->ownedGroup);

        $this->actingAs($user)->get('/console/admin/rules')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('slack_connected', true));
    }

    public function test_index_includes_slack_connected_false_when_no_integration(): void
    {
        $user = $this->makeManager();

        $this->actingAs($user)->get('/console/admin/rules')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('slack_connected', false));
    }

    public function test_index_queries_snapshot_tickets_only_once(): void
    {
        $user = $this->makeManager();

        TriageSnapshot::create([
            'user_id'          => $user->id,
            'license_key_hash' => hash('sha256', 'key'),
            'profile'          => 'work',
            'tickets'          => [['key' => 'X-1', 'status' => 'Open', 'priority' => 'Highest', 'labels' => ['a']]],
            'ticket_count'     => 1,
            'captured_at'      => now(),
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($user)->get('/console/admin/rules')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $snapshotQueries = array_filter($queries, fn ($q) => str_contains($q['query'], 'triage_snapshots'));
        $this->assertCount(1, $snapshotQueries, 'Expected exactly one triage_snapshots query, got ' . count($snapshotQueries));
    }

    public function test_index_includes_profiles_with_known_values(): void
    {
        $user = $this->makeManager();

        TrackerProfile::create([
            'user_id'         => $user->id,
            'name'            => 'acme-jira',
            'tracker_type'    => 'jira',
            'base_url'        => 'https://acme.atlassian.net',
            'auth_method'     => 'cloud',
            'email'           => $user->email,
            'ticket_prefixes' => ['ACME'],
            'known_statuses'  => ['Open', 'In Progress'],
        ]);

        $this->actingAs($user)->get('/console/admin/rules')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('profiles.0.name', 'acme-jira')
                ->where('profiles.0.owner_email', $user->email)
                ->where('profiles.0.known_statuses', ['Open', 'In Progress'])
                ->where('profiles.0.ticket_prefixes', ['ACME'])
            );
    }

    public function test_index_profiles_include_every_group_members_connections(): void
    {
        $manager = $this->makeManager();
        $member  = User::factory()->create(['tier' => 'team', 'permissions' => 2687]);
        $manager->ownedGroup->members()->attach($member->id);

        TrackerProfile::create([
            'user_id'      => $member->id,
            'name'         => 'member-jira',
            'tracker_type' => 'jira',
            'base_url'     => 'https://member.atlassian.net',
            'auth_method'  => 'cloud',
            'email'        => $member->email,
        ]);

        $this->actingAs($manager)->get('/console/admin/rules')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('profiles.0.owner_email', $member->email));
    }

    public function test_index_does_not_leak_other_groups_profiles(): void
    {
        $user = $this->makeManager();

        $otherUser = User::factory()->create(['tier' => 'team', 'permissions' => 2687]);
        TrackerProfile::create([
            'user_id'      => $otherUser->id,
            'name'         => 'other-team-jira',
            'tracker_type' => 'jira',
            'base_url'     => 'https://other.atlassian.net',
            'auth_method'  => 'cloud',
            'email'        => $otherUser->email,
        ]);

        $this->actingAs($user)->get('/console/admin/rules')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('profiles', []));
    }

    public function test_index_includes_unconnected_members_when_some_lack_trackerprofile(): void
    {
        $manager = $this->makeManager();

        $this->actingAs($manager)->get('/console/admin/rules')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('unconnected_members', 1)
                ->where('unconnected_members.0.name', $manager->name)
            );
    }

    public function test_index_unconnected_members_empty_when_all_connected(): void
    {
        $manager = $this->makeManager();

        TrackerProfile::create([
            'user_id'      => $manager->id,
            'name'         => 'manager-jira',
            'tracker_type' => 'jira',
            'base_url'     => 'https://manager.atlassian.net',
            'auth_method'  => 'cloud',
            'email'        => $manager->email,
        ]);

        $this->actingAs($manager)->get('/console/admin/rules')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('unconnected_members', []));
    }

    public function test_index_unconnected_members_lists_only_members_missing_a_profile(): void
    {
        $manager = $this->makeManager();
        $member  = User::factory()->create(['tier' => 'team', 'permissions' => 2687]);
        $manager->ownedGroup->members()->attach($member->id);

        TrackerProfile::create([
            'user_id'      => $manager->id,
            'name'         => 'manager-jira',
            'tracker_type' => 'jira',
            'base_url'     => 'https://manager.atlassian.net',
            'auth_method'  => 'cloud',
            'email'        => $manager->email,
        ]);

        $this->actingAs($manager)->get('/console/admin/rules')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('unconnected_members', 1)
                ->where('unconnected_members.0.name', $member->name)
            );
    }

    public function test_index_does_not_add_extra_trackerprofile_query(): void
    {
        $manager = $this->makeManager();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($manager)->get('/console/admin/rules')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Scoped to buildRuleData()'s own queries — excludes the unrelated groups()
        // lookup HandleInertiaRequests already runs on every request for shared auth props.
        $rosterQueries = array_filter(
            $queries,
            fn ($q) => str_contains($q['query'], 'from "users"') && str_contains($q['query'], 'group_user')
        );
        $profileQueries = array_filter($queries, fn ($q) => str_contains($q['query'], 'tracker_profiles'));

        // 1 query for the group roster (users x group_user) + 1 for TrackerProfile::whereIn.
        // No extra query — unconnected members must be derived in-memory from these two.
        $this->assertCount(1, $rosterQueries, 'Expected exactly 1 roster query, got ' . count($rosterQueries));
        $this->assertCount(1, $profileQueries, 'Expected exactly 1 tracker_profiles query, got ' . count($profileQueries));
    }

    public function test_owner_empty_state_includes_unconnected_members_key(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)->get('/console/admin/rules')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('unconnected_members', []));
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

    public function test_save_stale_flash_is_scoped_to_stale_card(): void
    {
        $user = $this->makeManager();

        $this->actingAs($user)->post('/console/admin/rules/stale', [
            'enabled'    => true,
            'stale_days' => 14,
            'statuses'   => ['In Review'],
        ])->assertSessionHas('rule_type', 'stale');
    }

    public function test_save_custom_flash_is_scoped_to_custom_card(): void
    {
        $user = $this->makeManager();

        $this->actingAs($user)->post('/console/admin/rules/custom', [
            'enabled' => true,
            'rules'   => [['match' => ['priority' => 'Highest'], 'action' => 'force-urgent', 'reason' => null]],
        ])->assertSessionHas('rule_type', 'custom');
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

    // ── Save custom rule ──────────────────────────────────────────────────────

    public function test_save_custom_creates_rule(): void
    {
        $user  = $this->makeManager();
        $group = $user->ownedGroup;

        $this->actingAs($user)->post('/console/admin/rules/custom', [
            'enabled' => true,
            'rules'   => [
                ['match' => ['priority' => 'Highest'], 'action' => 'force-urgent', 'reason' => 'P1 always urgent'],
            ],
        ])->assertRedirect();

        $this->assertDatabaseHas('workflow_rules', [
            'group_id' => $group->id,
            'type'     => 'custom',
            'enabled'  => true,
        ]);
    }

    public function test_save_custom_strips_control_characters_from_reason(): void
    {
        $user  = $this->makeManager();
        $group = $user->ownedGroup;

        $this->actingAs($user)->post('/console/admin/rules/custom', [
            'enabled' => true,
            'rules'   => [
                ['match' => ['priority' => 'Highest'], 'action' => 'force-urgent', 'reason' => "P1\x1b[31m urgent\x07"],
            ],
        ])->assertRedirect();

        $rule = WorkflowRule::where('group_id', $group->id)->where('type', 'custom')->first();
        $this->assertSame('P1[31m urgent', $rule->config['rules'][0]['reason']);
    }

    public function test_save_custom_updates_existing_rule(): void
    {
        $user  = $this->makeManager();
        $group = $user->ownedGroup;

        WorkflowRule::create([
            'group_id' => $group->id,
            'type'     => 'custom',
            'config'   => ['rules' => [['match' => ['label' => 'backlog'], 'action' => 'ignore', 'reason' => null]]],
            'enabled'  => true,
        ]);

        $this->actingAs($user)->post('/console/admin/rules/custom', [
            'enabled' => false,
            'rules'   => [
                ['match' => ['status' => 'Blocked'], 'action' => 'force-urgent', 'reason' => 'blocked'],
            ],
        ])->assertRedirect();

        $this->assertEquals(1, WorkflowRule::where('group_id', $group->id)->where('type', 'custom')->count());
        $rule = WorkflowRule::where('group_id', $group->id)->where('type', 'custom')->first();
        $this->assertFalse($rule->enabled);
        $this->assertEquals('Blocked', $rule->config['rules'][0]['match']['status']);
    }

    public function test_save_custom_rejects_invalid_action(): void
    {
        $user = $this->makeManager();

        $this->actingAs($user)->post('/console/admin/rules/custom', [
            'enabled' => true,
            'rules'   => [
                ['match' => ['priority' => 'Highest'], 'action' => 'delete-ticket', 'reason' => null],
            ],
        ])->assertSessionHasErrors('rules.0.action');
    }

    public function test_save_custom_accepts_notify_action_when_slack_connected(): void
    {
        $user = $this->makeManager();
        $this->connectSlack($user->ownedGroup);

        $this->actingAs($user)->post('/console/admin/rules/custom', [
            'enabled' => true,
            'rules'   => [
                ['match' => ['priority' => 'Highest'], 'action' => 'notify', 'reason' => 'ping the team'],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('notify', WorkflowRule::where('type', 'custom')->first()->config['rules'][0]['action']);
    }

    public function test_save_custom_accepts_schedule_action_when_slack_connected(): void
    {
        $user = $this->makeManager();
        $this->connectSlack($user->ownedGroup);

        $this->actingAs($user)->post('/console/admin/rules/custom', [
            'enabled' => true,
            'rules'   => [
                ['match' => ['priority' => 'Low'], 'action' => 'schedule', 'reason' => 'batch weekly'],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('schedule', WorkflowRule::where('type', 'custom')->first()->config['rules'][0]['action']);
    }

    public function test_save_custom_rejects_notify_action_without_slack_integration(): void
    {
        $user = $this->makeManager();

        $this->actingAs($user)->post('/console/admin/rules/custom', [
            'enabled' => true,
            'rules'   => [
                ['match' => ['priority' => 'Highest'], 'action' => 'notify', 'reason' => 'ping the team'],
            ],
        ])->assertSessionHasErrors('rules.0.action');

        $this->assertSame(0, WorkflowRule::where('type', 'custom')->count());
    }

    public function test_save_custom_rejects_schedule_action_without_slack_integration(): void
    {
        $user = $this->makeManager();

        $this->actingAs($user)->post('/console/admin/rules/custom', [
            'enabled' => true,
            'rules'   => [
                ['match' => ['priority' => 'Low'], 'action' => 'schedule', 'reason' => 'batch weekly'],
            ],
        ])->assertSessionHasErrors('rules.0.action');

        $this->assertSame(0, WorkflowRule::where('type', 'custom')->count());
    }

    public function test_save_custom_rejects_rule_with_empty_match(): void
    {
        $user = $this->makeManager();

        $this->actingAs($user)->post('/console/admin/rules/custom', [
            'enabled' => true,
            'rules'   => [
                ['match' => [], 'action' => 'force-urgent', 'reason' => null],
            ],
        ])->assertSessionHasErrors('rules.0.match');
    }

    public function test_save_custom_rejects_more_than_50_rules(): void
    {
        $user  = $this->makeManager();
        $rules = array_fill(0, 51, ['match' => ['priority' => 'Highest'], 'action' => 'force-urgent', 'reason' => null]);

        $this->actingAs($user)->post('/console/admin/rules/custom', [
            'enabled' => true,
            'rules'   => $rules,
        ])->assertSessionHasErrors('rules');
    }

    public function test_save_custom_rejects_empty_rules_array(): void
    {
        $user = $this->makeManager();

        $this->actingAs($user)->post('/console/admin/rules/custom', [
            'enabled' => true,
            'rules'   => [],
        ])->assertSessionHasErrors('rules');
    }

    public function test_save_custom_blocks_non_manager_via_middleware(): void
    {
        $user  = User::factory()->create(['tier' => 'free', 'permissions' => 64]);
        $group = Group::create(['name' => 'Free', 'owner_id' => $user->id]);
        $group->members()->attach($user->id);

        $this->actingAs($user)->post('/console/admin/rules/custom', [
            'enabled' => true,
            'rules'   => [['match' => ['priority' => 'Highest'], 'action' => 'force-urgent', 'reason' => null]],
        ])->assertRedirect('/console/dashboard');
    }

    public function test_non_manager_member_cannot_save_custom_rule(): void
    {
        $manager = $this->makeManager();
        $member  = User::factory()->create(['tier' => 'team', 'permissions' => 2687]);
        $manager->ownedGroup->members()->attach($member->id);

        $this->actingAs($member)->post('/console/admin/rules/custom', [
            'enabled' => true,
            'rules'   => [['match' => ['priority' => 'Highest'], 'action' => 'force-urgent', 'reason' => null]],
        ])->assertRedirect('/console/dashboard');
    }

    public function test_save_custom_publishes_rule_changed_event(): void
    {
        $manager = $this->makeManager();
        $group   = $manager->ownedGroup;

        $this->mock(SseEventService::class)
            ->shouldReceive('publish')
            ->once()
            ->with($group->id, 'rule.changed', []);

        $this->actingAs($manager)->post('/console/admin/rules/custom', [
            'enabled' => true,
            'rules'   => [['match' => ['priority' => 'Highest'], 'action' => 'force-urgent', 'reason' => null]],
        ])->assertRedirect();
    }

    public function test_owner_can_save_custom_rule_for_selected_manager(): void
    {
        $owner   = $this->makeOwner();
        $manager = $this->makeManager();

        $this->actingAs($owner)->post('/console/admin/rules/custom', [
            'manager_id' => $manager->id,
            'enabled'    => true,
            'rules'      => [['match' => ['priority' => 'Highest'], 'action' => 'force-urgent', 'reason' => null]],
        ])->assertRedirect();

        $this->assertDatabaseHas('workflow_rules', [
            'group_id' => $manager->ownedGroup->id,
            'type'     => 'custom',
            'enabled'  => true,
        ]);
    }

    public function test_owner_save_custom_without_manager_id_returns_422(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)->post('/console/admin/rules/custom', [
            'enabled' => true,
            'rules'   => [['match' => ['priority' => 'Highest'], 'action' => 'force-urgent', 'reason' => null]],
        ])->assertStatus(422);
    }

    // ── Toggle custom rule ────────────────────────────────────────────────────

    public function test_toggle_custom_disables_rule(): void
    {
        $user  = $this->makeManager();
        $group = $user->ownedGroup;

        $rule = WorkflowRule::create([
            'group_id' => $group->id,
            'type'     => 'custom',
            'config'   => ['rules' => [['match' => ['priority' => 'Highest'], 'action' => 'force-urgent', 'reason' => null]]],
            'enabled'  => true,
        ]);

        $this->actingAs($user)
            ->patch('/console/admin/rules/custom/toggle', ['enabled' => false])
            ->assertRedirect();

        $this->assertDatabaseHas('workflow_rules', ['id' => $rule->id, 'enabled' => false]);
    }

    public function test_toggle_custom_enables_rule(): void
    {
        $user  = $this->makeManager();
        $group = $user->ownedGroup;

        $rule = WorkflowRule::create([
            'group_id' => $group->id,
            'type'     => 'custom',
            'config'   => ['rules' => [['match' => ['priority' => 'Highest'], 'action' => 'force-urgent', 'reason' => null]]],
            'enabled'  => false,
        ]);

        $this->actingAs($user)
            ->patch('/console/admin/rules/custom/toggle', ['enabled' => true])
            ->assertRedirect();

        $this->assertDatabaseHas('workflow_rules', ['id' => $rule->id, 'enabled' => true]);
    }

    public function test_toggle_custom_returns_404_when_no_rule(): void
    {
        $user = $this->makeManager();

        $this->actingAs($user)
            ->patch('/console/admin/rules/custom/toggle', ['enabled' => true])
            ->assertStatus(404);
    }

    // ── Destroy custom rule ───────────────────────────────────────────────────

    public function test_destroy_custom_deletes_rule(): void
    {
        $user  = $this->makeManager();
        $group = $user->ownedGroup;

        WorkflowRule::create([
            'group_id' => $group->id,
            'type'     => 'custom',
            'config'   => ['rules' => [['match' => ['priority' => 'Highest'], 'action' => 'force-urgent', 'reason' => null]]],
            'enabled'  => true,
        ]);

        $this->actingAs($user)->delete('/console/admin/rules/custom')->assertRedirect();

        $this->assertDatabaseMissing('workflow_rules', ['group_id' => $group->id, 'type' => 'custom']);
    }

    public function test_non_manager_member_cannot_delete_custom_rule(): void
    {
        $manager = $this->makeManager();
        $member  = User::factory()->create(['tier' => 'team', 'permissions' => 2687]);
        $manager->ownedGroup->members()->attach($member->id);

        WorkflowRule::create([
            'group_id' => $manager->ownedGroup->id,
            'type'     => 'custom',
            'config'   => ['rules' => [['match' => ['priority' => 'Highest'], 'action' => 'force-urgent', 'reason' => null]]],
            'enabled'  => true,
        ]);

        $this->actingAs($member)->delete('/console/admin/rules/custom')
            ->assertRedirect('/console/dashboard');

        $this->assertDatabaseHas('workflow_rules', [
            'group_id' => $manager->ownedGroup->id,
            'type'     => 'custom',
        ]);
    }

    public function test_owner_index_returns_custom_rule_null_when_no_manager_selected(): void
    {
        $owner = $this->makeOwner();
        $this->makeManager();

        $this->actingAs($owner)->get('/console/admin/rules')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Console/Admin/Rules')
                ->where('owner_mode', true)
                ->where('custom_rule', null)
            );
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

    public function test_toggle_stale_disables_rule(): void
    {
        $user  = $this->makeManager();
        $group = $user->ownedGroup;

        $rule = WorkflowRule::create([
            'group_id' => $group->id,
            'type'     => 'stale',
            'config'   => ['stale_days' => 7, 'statuses' => ['In Review']],
            'enabled'  => true,
        ]);

        $this->actingAs($user)
            ->patch('/console/admin/rules/stale/toggle', ['enabled' => false])
            ->assertRedirect();

        $this->assertDatabaseHas('workflow_rules', ['id' => $rule->id, 'enabled' => false]);
    }

    public function test_toggle_stale_enables_rule(): void
    {
        $user  = $this->makeManager();
        $group = $user->ownedGroup;

        $rule = WorkflowRule::create([
            'group_id' => $group->id,
            'type'     => 'stale',
            'config'   => ['stale_days' => 7, 'statuses' => ['In Review']],
            'enabled'  => false,
        ]);

        $this->actingAs($user)
            ->patch('/console/admin/rules/stale/toggle', ['enabled' => true])
            ->assertRedirect();

        $this->assertDatabaseHas('workflow_rules', ['id' => $rule->id, 'enabled' => true]);
    }

    public function test_toggle_stale_returns_404_when_no_rule(): void
    {
        $user = $this->makeManager();

        $this->actingAs($user)
            ->patch('/console/admin/rules/stale/toggle', ['enabled' => true])
            ->assertStatus(404);
    }

    public function test_toggle_stale_blocks_non_manager(): void
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

        $this->actingAs($member)
            ->patch('/console/admin/rules/stale/toggle', ['enabled' => false])
            ->assertRedirect('/console/dashboard');
    }

    // ── Owner mode ────────────────────────────────────────────────────────────

    private function makeOwner(): User
    {
        return User::factory()->create(['tier' => 'owner', 'is_owner' => true, 'permissions' => 0]);
    }

    public function test_owner_sees_client_picker_without_manager_id(): void
    {
        $owner   = $this->makeOwner();
        $manager = $this->makeManager();

        $this->actingAs($owner)->get('/console/admin/rules')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Console/Admin/Rules')
                ->where('owner_mode', true)
                ->where('selected_manager', null)
                ->has('clients')
                ->where('clients.0.avatar_url', null)
            );
    }

    public function test_owner_with_manager_id_sees_team_rules(): void
    {
        $owner   = $this->makeOwner();
        $manager = $this->makeManager();

        WorkflowRule::create([
            'group_id' => $manager->ownedGroup->id,
            'type'     => 'stale',
            'config'   => ['stale_days' => 7, 'statuses' => ['In Review']],
            'enabled'  => true,
        ]);

        $this->actingAs($owner)->get("/console/admin/rules?manager_id={$manager->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Console/Admin/Rules')
                ->where('owner_mode', true)
                ->where('selected_manager.id', $manager->id)
                ->where('stale_rule.enabled', true)
            );
    }

    public function test_owner_can_save_stale_rule_for_selected_manager(): void
    {
        $owner   = $this->makeOwner();
        $manager = $this->makeManager();

        $this->actingAs($owner)->post('/console/admin/rules/stale', [
            'manager_id' => $manager->id,
            'enabled'    => true,
            'stale_days' => 10,
            'statuses'   => ['In Review', 'Blocked'],
        ])->assertRedirect();

        $this->assertDatabaseHas('workflow_rules', [
            'group_id' => $manager->ownedGroup->id,
            'type'     => 'stale',
            'enabled'  => true,
        ]);
    }

    public function test_owner_save_stale_without_manager_id_returns_422(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)->post('/console/admin/rules/stale', [
            'enabled'    => true,
            'stale_days' => 7,
            'statuses'   => ['In Review'],
        ])->assertStatus(422);
    }

    public function test_owner_can_toggle_stale_rule_for_selected_manager(): void
    {
        $owner   = $this->makeOwner();
        $manager = $this->makeManager();

        $rule = WorkflowRule::create([
            'group_id' => $manager->ownedGroup->id,
            'type'     => 'stale',
            'config'   => ['stale_days' => 7, 'statuses' => ['In Review']],
            'enabled'  => true,
        ]);

        $this->actingAs($owner)->patch('/console/admin/rules/stale/toggle', [
            'manager_id' => $manager->id,
            'enabled'    => false,
        ])->assertRedirect();

        $this->assertDatabaseHas('workflow_rules', ['id' => $rule->id, 'enabled' => false]);
    }

    public function test_owner_can_delete_stale_rule_for_selected_manager(): void
    {
        $owner   = $this->makeOwner();
        $manager = $this->makeManager();

        WorkflowRule::create([
            'group_id' => $manager->ownedGroup->id,
            'type'     => 'stale',
            'config'   => ['stale_days' => 7, 'statuses' => ['In Review']],
            'enabled'  => true,
        ]);

        $this->actingAs($owner)->delete('/console/admin/rules/stale', [
            'manager_id' => $manager->id,
        ])->assertRedirect();

        $this->assertDatabaseMissing('workflow_rules', ['group_id' => $manager->ownedGroup->id, 'type' => 'stale']);
    }

    public function test_owner_cannot_target_non_manager_user(): void
    {
        $owner  = $this->makeOwner();
        $member = User::factory()->create(['tier' => 'team', 'permissions' => 2687]);

        $this->actingAs($owner)->post('/console/admin/rules/stale', [
            'manager_id' => $member->id,
            'enabled'    => true,
            'stale_days' => 7,
            'statuses'   => ['In Review'],
        ])->assertStatus(422);
    }

    // ── SSE publish ───────────────────────────────────────────────────────────

    public function test_save_stale_publishes_rule_changed_event(): void
    {
        $manager = $this->makeManager();
        $group   = $manager->ownedGroup;

        $this->mock(SseEventService::class)
            ->shouldReceive('publish')
            ->once()
            ->with($group->id, 'rule.changed', []);

        $this->actingAs($manager)->post('/console/admin/rules/stale', [
            'enabled'    => true,
            'stale_days' => 7,
            'statuses'   => ['In Progress'],
        ])->assertRedirect();
    }

    public function test_toggle_stale_publishes_rule_changed_event(): void
    {
        $manager = $this->makeManager();
        $group   = $manager->ownedGroup;

        WorkflowRule::create([
            'group_id' => $group->id,
            'type'     => 'stale',
            'config'   => ['stale_days' => 7, 'statuses' => ['In Progress']],
            'enabled'  => true,
        ]);

        $this->mock(SseEventService::class)
            ->shouldReceive('publish')
            ->once()
            ->with($group->id, 'rule.changed', []);

        $this->actingAs($manager)
            ->patch('/console/admin/rules/stale/toggle', ['enabled' => false])
            ->assertRedirect();
    }

    public function test_destroy_stale_publishes_rule_changed_event(): void
    {
        $manager = $this->makeManager();
        $group   = $manager->ownedGroup;

        WorkflowRule::create([
            'group_id' => $group->id,
            'type'     => 'stale',
            'config'   => ['stale_days' => 7, 'statuses' => ['In Progress']],
            'enabled'  => true,
        ]);

        $this->mock(SseEventService::class)
            ->shouldReceive('publish')
            ->once()
            ->with($group->id, 'rule.changed', []);

        $this->actingAs($manager)->delete('/console/admin/rules/stale')->assertRedirect();
    }
}
