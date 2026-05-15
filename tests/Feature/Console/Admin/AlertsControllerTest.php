<?php

namespace Tests\Feature\Console\Admin;

use App\Models\AlertSetting;
use App\Models\CustomAlertRule;
use App\Models\Group;
use App\Models\License;
use App\Models\SlackDigestSchedule;
use App\Models\SlackIntegration;
use App\Models\User;
use App\Services\SlackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertsControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeManager(): User
    {
        $manager = User::factory()->create(['tier' => 'team', 'permissions' => 511]);
        $group   = Group::create(['name' => "Team {$manager->id}", 'owner_id' => $manager->id]);
        $group->members()->attach($manager->id);
        License::create([
            'user_id'        => $manager->id,
            'lemon_key_hash' => hash('sha256', 'mgr-' . $manager->id),
            'status'         => 'active',
            'tier'           => 'team',
            'seats'          => 5,
        ]);
        return $manager;
    }

    private function makeOwner(): User
    {
        return User::factory()->create([
            'tier'        => 'owner',
            'permissions' => 0,
            'is_owner'    => true,
        ]);
    }

    private function makeSlack(Group $group): SlackIntegration
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

    private function makeRule(Group $group, array $overrides = []): CustomAlertRule
    {
        return CustomAlertRule::create(array_merge([
            'group_id'     => $group->id,
            'alert_type'   => 'aging',
            'integration'  => 'slack',
            'target_id'    => 'U123',
            'target_label' => 'Alice',
            'enabled'      => true,
        ], $overrides));
    }

    // ── Access control ────────────────────────────────────────────────────────

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/console/admin/alerts')->assertRedirect('/console/login');
    }

    public function test_plain_team_member_cannot_access_alerts(): void
    {
        $member = User::factory()->create(['tier' => 'team', 'permissions' => 127]);
        $this->actingAs($member)->get('/console/admin/alerts')->assertRedirect('/console/dashboard');
    }

    public function test_manager_can_view_alerts_page(): void
    {
        $manager = $this->makeManager();
        $this->actingAs($manager)->get('/console/admin/alerts')->assertOk();
    }

    public function test_owner_can_view_alerts_for_any_group(): void
    {
        $owner   = $this->makeOwner();
        $manager = $this->makeManager();
        $group   = $manager->ownedGroup;

        $this->actingAs($owner)->get("/console/owner/alerts?group_id={$group->id}")->assertOk();
    }

    // ── Default settings ──────────────────────────────────────────────────────

    public function test_index_returns_defaults_when_no_settings_exist(): void
    {
        $manager = $this->makeManager();

        $this->actingAs($manager)->get('/console/admin/alerts')
            ->assertInertia(fn ($page) => $page
                ->component('Console/Admin/Alerts')
                ->where('settings.needs_response_enabled', false)
                ->where('settings.needs_response_cooldown_hours', 4)
                ->where('settings.aging_enabled', false)
                ->where('settings.aging_cooldown_hours', 24)
            );
    }

    public function test_index_returns_existing_settings(): void
    {
        $manager = $this->makeManager();
        $group   = $manager->ownedGroup;
        AlertSetting::create([
            'group_id'                      => $group->id,
            'needs_response_enabled'        => true,
            'needs_response_cooldown_hours' => 8,
            'aging_enabled'                 => false,
            'aging_cooldown_hours'          => 48,
        ]);

        $this->actingAs($manager)->get('/console/admin/alerts')
            ->assertInertia(fn ($page) => $page
                ->where('settings.needs_response_enabled', true)
                ->where('settings.needs_response_cooldown_hours', 8)
                ->where('settings.aging_enabled', false)
                ->where('settings.aging_cooldown_hours', 48)
            );
    }

    public function test_index_returns_existing_rules(): void
    {
        $manager = $this->makeManager();
        $this->makeRule($manager->ownedGroup);

        $this->actingAs($manager)->get('/console/admin/alerts')
            ->assertInertia(fn ($page) => $page
                ->has('rules.data', 1)
                ->where('rules.data.0.alert_type', 'aging')
                ->where('rules.data.0.target_id', 'U123')
            );
    }

    // ── Save needs-response ───────────────────────────────────────────────────

    public function test_manager_can_save_needs_response_settings(): void
    {
        $manager = $this->makeManager();

        $this->actingAs($manager)->patch('/console/admin/alerts/needs-response', [
            'enabled'        => true,
            'cooldown_hours' => 8,
        ])->assertRedirect();

        $this->assertDatabaseHas('alert_settings', [
            'group_id'                      => $manager->ownedGroup->id,
            'needs_response_enabled'        => true,
            'needs_response_cooldown_hours' => 8,
        ]);
    }

    public function test_manager_can_save_aging_settings(): void
    {
        $manager = $this->makeManager();

        $this->actingAs($manager)->patch('/console/admin/alerts/aging', [
            'enabled'        => true,
            'cooldown_hours' => 48,
        ])->assertRedirect();

        $this->assertDatabaseHas('alert_settings', [
            'group_id'             => $manager->ownedGroup->id,
            'aging_enabled'        => true,
            'aging_cooldown_hours' => 48,
        ]);
    }

    public function test_save_upserts_on_second_call(): void
    {
        $manager = $this->makeManager();
        $group   = $manager->ownedGroup;

        $this->actingAs($manager)->patch('/console/admin/alerts/needs-response', [
            'enabled' => true, 'cooldown_hours' => 4,
        ]);
        $this->actingAs($manager)->patch('/console/admin/alerts/needs-response', [
            'enabled' => false, 'cooldown_hours' => 12,
        ]);

        $this->assertSame(1, AlertSetting::where('group_id', $group->id)->count());
        $this->assertDatabaseHas('alert_settings', [
            'group_id'                      => $group->id,
            'needs_response_enabled'        => false,
            'needs_response_cooldown_hours' => 12,
        ]);
    }

    public function test_save_requires_boolean_enabled(): void
    {
        $manager = $this->makeManager();

        $this->actingAs($manager)->patch('/console/admin/alerts/needs-response', [
            'enabled'        => 'not-a-bool',
            'cooldown_hours' => 4,
        ])->assertSessionHasErrors(['enabled']);
    }

    public function test_save_requires_cooldown_in_range(): void
    {
        $manager = $this->makeManager();

        $this->actingAs($manager)->patch('/console/admin/alerts/needs-response', [
            'enabled' => true, 'cooldown_hours' => 0,
        ])->assertSessionHasErrors(['cooldown_hours']);

        $this->actingAs($manager)->patch('/console/admin/alerts/needs-response', [
            'enabled' => true, 'cooldown_hours' => 721,
        ])->assertSessionHasErrors(['cooldown_hours']);
    }

    public function test_owner_can_save_alerts_for_any_group(): void
    {
        $owner   = $this->makeOwner();
        $manager = $this->makeManager();
        $group   = $manager->ownedGroup;

        $this->actingAs($owner)->patch("/console/owner/alerts/needs-response?group_id={$group->id}", [
            'enabled'        => true,
            'cooldown_hours' => 6,
        ])->assertRedirect();

        $this->assertDatabaseHas('alert_settings', [
            'group_id'               => $group->id,
            'needs_response_enabled' => true,
        ]);
    }

    public function test_plain_member_cannot_save_settings(): void
    {
        $member = User::factory()->create(['tier' => 'team', 'permissions' => 127]);

        $this->actingAs($member)->patch('/console/admin/alerts/needs-response', [
            'enabled'        => true,
            'cooldown_hours' => 4,
        ])->assertRedirect('/console/dashboard');
    }

    // ── Custom rules ──────────────────────────────────────────────────────────

    public function test_manager_can_store_a_custom_rule(): void
    {
        $manager = $this->makeManager();

        $this->actingAs($manager)->post('/console/admin/alerts/rules', [
            'alert_type' => 'aging',
            'targets'    => [['id' => 'U123', 'label' => 'Alice']],
        ])->assertRedirect();

        $this->assertDatabaseHas('custom_alert_rules', [
            'group_id'     => $manager->ownedGroup->id,
            'alert_type'   => 'aging',
            'target_id'    => 'U123',
            'target_label' => 'Alice',
            'enabled'      => true,
        ]);
    }

    public function test_store_rule_is_idempotent(): void
    {
        $manager = $this->makeManager();
        $payload = [
            'alert_type' => 'needs_response',
            'targets'    => [['id' => 'U123', 'label' => 'Alice']],
        ];

        $this->actingAs($manager)->post('/console/admin/alerts/rules', $payload);
        $this->actingAs($manager)->post('/console/admin/alerts/rules', $payload);

        $this->assertSame(1, CustomAlertRule::where('group_id', $manager->ownedGroup->id)->count());
    }

    public function test_store_rule_accepts_multiple_targets(): void
    {
        $manager = $this->makeManager();

        $this->actingAs($manager)->post('/console/admin/alerts/rules', [
            'alert_type' => 'aging',
            'targets'    => [
                ['id' => 'U111', 'label' => 'Alice'],
                ['id' => 'U222', 'label' => 'Bob'],
            ],
        ])->assertRedirect();

        $this->assertSame(2, CustomAlertRule::where('group_id', $manager->ownedGroup->id)->count());
    }

    public function test_manager_can_toggle_a_rule(): void
    {
        $manager = $this->makeManager();
        $rule    = $this->makeRule($manager->ownedGroup, ['enabled' => true]);

        $this->actingAs($manager)->patch("/console/admin/alerts/rules/{$rule->id}", [
            'enabled' => false,
        ])->assertRedirect();

        $this->assertDatabaseHas('custom_alert_rules', ['id' => $rule->id, 'enabled' => false]);
    }

    public function test_manager_can_delete_a_rule(): void
    {
        $manager = $this->makeManager();
        $rule    = $this->makeRule($manager->ownedGroup);

        $this->actingAs($manager)->delete("/console/admin/alerts/rules/{$rule->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('custom_alert_rules', ['id' => $rule->id]);
    }

    public function test_cannot_modify_rule_of_another_group(): void
    {
        $managerA = $this->makeManager();
        $managerB = $this->makeManager();
        $rule     = $this->makeRule($managerA->ownedGroup);

        $this->actingAs($managerB)->patch("/console/admin/alerts/rules/{$rule->id}", [
            'enabled' => false,
        ])->assertForbidden();

        $this->actingAs($managerB)->delete("/console/admin/alerts/rules/{$rule->id}")
            ->assertForbidden();
    }

    // ── Fetch members ─────────────────────────────────────────────────────────

    public function test_fetch_members_returns_error_when_no_integration(): void
    {
        $manager = $this->makeManager();

        $this->actingAs($manager)
            ->getJson('/console/admin/alerts/members')
            ->assertUnprocessable()
            ->assertJsonFragment(['error' => 'No Slack integration connected for this team.']);
    }

    public function test_fetch_members_returns_member_list(): void
    {
        $manager = $this->makeManager();
        $this->makeSlack($manager->ownedGroup);

        $this->mock(SlackService::class)
            ->shouldReceive('fetchMembers')
            ->once()
            ->andReturn([
                ['id' => 'U1', 'name' => 'Alice', 'real_name' => 'Alice Smith', 'avatar' => null],
            ]);

        $this->actingAs($manager)
            ->getJson('/console/admin/alerts/members')
            ->assertOk()
            ->assertJsonFragment(['id' => 'U1']);
    }

    // ── Compliance gap settings ───────────────────────────────────────────────

    public function test_index_returns_compliance_gap_defaults(): void
    {
        $manager = $this->makeManager();

        $this->actingAs($manager)->get('/console/admin/alerts')
            ->assertInertia(fn ($page) => $page
                ->where('settings.compliance_gap_enabled', false)
                ->where('settings.compliance_gap_cooldown_hours', 24)
            );
    }

    public function test_manager_can_save_compliance_gap_settings(): void
    {
        $manager = $this->makeManager();

        $this->actingAs($manager)->patch('/console/admin/alerts/compliance-gap', [
            'enabled'        => true,
            'cooldown_hours' => 48,
        ])->assertRedirect();

        $this->assertDatabaseHas('alert_settings', [
            'group_id'                         => $manager->ownedGroup->id,
            'compliance_gap_enabled'           => true,
            'compliance_gap_cooldown_hours'    => 48,
        ]);
    }

    public function test_owner_can_save_compliance_gap_for_any_group(): void
    {
        $owner   = $this->makeOwner();
        $manager = $this->makeManager();
        $group   = $manager->ownedGroup;

        $this->actingAs($owner)->patch("/console/owner/alerts/compliance-gap?group_id={$group->id}", [
            'enabled'        => true,
            'cooldown_hours' => 24,
        ])->assertRedirect();

        $this->assertDatabaseHas('alert_settings', [
            'group_id'               => $group->id,
            'compliance_gap_enabled' => true,
        ]);
    }

    // ── Digest schedules ──────────────────────────────────────────────────────

    public function test_index_returns_digest_schedules_for_group(): void
    {
        $manager = $this->makeManager();
        $group   = $manager->ownedGroup;

        SlackDigestSchedule::create([
            'group_id'     => $group->id,
            'day_of_week'  => 1,
            'deliver_at'   => '09:00',
            'timezone'     => 'UTC',
            'target_type'  => 'channel',
            'target_id'    => 'C001',
            'target_label' => '#general',
        ]);

        $this->actingAs($manager)->get('/console/admin/alerts')
            ->assertInertia(fn ($page) => $page
                ->has('digestSchedules.data', 1)
                ->where('digestSchedules.data.0.deliver_at', '09:00')
            );
    }

    public function test_manager_can_store_digest_schedule(): void
    {
        $manager = $this->makeManager();

        $this->actingAs($manager)->post('/console/admin/alerts/digest-schedules', [
            'day_of_week' => 1,
            'deliver_at'  => '09:00',
            'timezone'    => 'America/New_York',
            'target_type' => 'channel',
            'targets'     => [['id' => 'C001', 'label' => '#general']],
        ])->assertRedirect();

        $this->assertDatabaseHas('slack_digest_schedules', [
            'group_id'     => $manager->ownedGroup->id,
            'day_of_week'  => 1,
            'deliver_at'   => '09:00',
            'timezone'     => 'America/New_York',
            'target_id'    => 'C001',
            'target_label' => '#general',
            'active'       => true,
        ]);
    }

    public function test_store_creates_one_row_per_target(): void
    {
        $manager = $this->makeManager();

        $this->actingAs($manager)->post('/console/admin/alerts/digest-schedules', [
            'day_of_week' => 2,
            'deliver_at'  => '10:00',
            'timezone'    => 'UTC',
            'target_type' => 'channel',
            'targets'     => [
                ['id' => 'C001', 'label' => '#general'],
                ['id' => 'C002', 'label' => '#eng'],
            ],
        ])->assertRedirect();

        $this->assertDatabaseHas('slack_digest_schedules', ['target_id' => 'C001', 'group_id' => $manager->ownedGroup->id]);
        $this->assertDatabaseHas('slack_digest_schedules', ['target_id' => 'C002', 'group_id' => $manager->ownedGroup->id]);
    }

    public function test_store_digest_schedule_validates_required_fields(): void
    {
        $manager = $this->makeManager();

        $this->actingAs($manager)->post('/console/admin/alerts/digest-schedules', [])
            ->assertSessionHasErrors(['day_of_week', 'deliver_at', 'timezone', 'target_type', 'targets']);
    }

    public function test_store_digest_schedule_validates_day_of_week_range(): void
    {
        $manager = $this->makeManager();

        $this->actingAs($manager)->post('/console/admin/alerts/digest-schedules', [
            'day_of_week' => 7,
            'deliver_at'  => '09:00',
            'timezone'    => 'UTC',
            'target_type' => 'channel',
            'targets'     => [['id' => 'C001', 'label' => '#general']],
        ])->assertSessionHasErrors(['day_of_week']);
    }

    public function test_store_digest_schedule_validates_deliver_at_format(): void
    {
        $manager = $this->makeManager();

        $this->actingAs($manager)->post('/console/admin/alerts/digest-schedules', [
            'day_of_week' => 1,
            'deliver_at'  => '9am',
            'timezone'    => 'UTC',
            'target_type' => 'channel',
            'targets'     => [['id' => 'C001', 'label' => '#general']],
        ])->assertSessionHasErrors(['deliver_at']);
    }

    public function test_manager_can_toggle_digest_schedule(): void
    {
        $manager  = $this->makeManager();
        $schedule = $this->makeDigestSchedule($manager->ownedGroup, ['active' => true]);

        $this->actingAs($manager)
            ->patch("/console/admin/alerts/digest-schedules/{$schedule->id}", ['active' => false])
            ->assertRedirect();

        $this->assertDatabaseHas('slack_digest_schedules', ['id' => $schedule->id, 'active' => false]);
    }

    public function test_manager_cannot_toggle_another_groups_schedule(): void
    {
        $managerA = $this->makeManager();
        $managerB = $this->makeManager();
        $schedule = $this->makeDigestSchedule($managerA->ownedGroup);

        $this->actingAs($managerB)
            ->patch("/console/admin/alerts/digest-schedules/{$schedule->id}", ['active' => false])
            ->assertForbidden();
    }

    public function test_manager_can_delete_digest_schedule(): void
    {
        $manager  = $this->makeManager();
        $schedule = $this->makeDigestSchedule($manager->ownedGroup);

        $this->actingAs($manager)
            ->delete("/console/admin/alerts/digest-schedules/{$schedule->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('slack_digest_schedules', ['id' => $schedule->id]);
    }

    public function test_manager_cannot_delete_another_groups_schedule(): void
    {
        $managerA = $this->makeManager();
        $managerB = $this->makeManager();
        $schedule = $this->makeDigestSchedule($managerA->ownedGroup);

        $this->actingAs($managerB)
            ->delete("/console/admin/alerts/digest-schedules/{$schedule->id}")
            ->assertForbidden();
    }

    public function test_owner_can_store_digest_schedule_for_any_group(): void
    {
        $owner   = $this->makeOwner();
        $manager = $this->makeManager();
        $group   = $manager->ownedGroup;

        $this->actingAs($owner)->post("/console/owner/alerts/digest-schedules?group_id={$group->id}", [
            'day_of_week' => 5,
            'deliver_at'  => '08:00',
            'timezone'    => 'Europe/London',
            'target_type' => 'user',
            'targets'     => [['id' => 'U123', 'label' => 'Alice']],
        ])->assertRedirect();

        $this->assertDatabaseHas('slack_digest_schedules', [
            'group_id'    => $group->id,
            'day_of_week' => 5,
            'target_type' => 'user',
            'target_id'   => 'U123',
        ]);
    }

    // ── Save alert channel ────────────────────────────────────────────────────

    public function test_manager_can_update_alert_channel(): void
    {
        $manager = $this->makeManager();
        $this->makeSlack($manager->ownedGroup);

        $this->actingAs($manager)
            ->patchJson('/console/admin/alerts/channel', [
                'channel_id'   => 'C999',
                'channel_name' => 'engineering',
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('slack_integrations', [
            'group_id'     => $manager->ownedGroup->id,
            'channel_id'   => 'C999',
            'channel_name' => 'engineering',
        ]);
    }

    public function test_save_channel_alert_requires_slack_integration(): void
    {
        $manager = $this->makeManager();

        $this->actingAs($manager)
            ->patchJson('/console/admin/alerts/channel', [
                'channel_id'   => 'C999',
                'channel_name' => 'engineering',
            ])
            ->assertUnprocessable()
            ->assertJsonFragment(['error' => 'No Slack integration connected for this team.']);
    }

    // ── Fetch channels ────────────────────────────────────────────────────────

    public function test_fetch_channels_requires_slack_integration(): void
    {
        $manager = $this->makeManager();

        $this->actingAs($manager)
            ->getJson('/console/admin/alerts/channels')
            ->assertUnprocessable()
            ->assertJsonFragment(['error' => 'No Slack integration connected for this team.']);
    }

    public function test_manager_can_fetch_channels(): void
    {
        $manager = $this->makeManager();
        $this->makeSlack($manager->ownedGroup);

        $this->mock(SlackService::class)
            ->shouldReceive('fetchChannels')
            ->once()
            ->andReturn([['id' => 'C001', 'name' => 'general', 'is_private' => false]]);

        $this->actingAs($manager)
            ->getJson('/console/admin/alerts/channels')
            ->assertOk()
            ->assertJsonFragment(['id' => 'C001']);
    }

    // ── Test alerts ───────────────────────────────────────────────────────────

    public function test_test_alert_requires_slack_integration(): void
    {
        $manager = $this->makeManager();

        $this->actingAs($manager)
            ->postJson('/console/admin/alerts/needs-response/test')
            ->assertUnprocessable()
            ->assertJsonFragment(['error' => 'No Slack integration connected for this team.']);
    }

    public function test_manager_can_test_needs_response_alert(): void
    {
        $manager = $this->makeManager();
        $this->makeSlack($manager->ownedGroup);

        $this->mock(SlackService::class)
            ->shouldReceive('postMessage')
            ->once()
            ->with('xoxb-test', 'C001', \Mockery::on(fn ($t) => str_contains($t, 'Needs Response')));

        $this->actingAs($manager)
            ->postJson('/console/admin/alerts/needs-response/test')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_manager_can_test_aging_alert(): void
    {
        $manager = $this->makeManager();
        $this->makeSlack($manager->ownedGroup);

        $this->mock(SlackService::class)
            ->shouldReceive('postMessage')
            ->once()
            ->with('xoxb-test', 'C001', \Mockery::on(fn ($t) => str_contains($t, 'Aging')));

        $this->actingAs($manager)
            ->postJson('/console/admin/alerts/aging/test')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_manager_can_test_compliance_gap_alert(): void
    {
        $manager = $this->makeManager();
        $this->makeSlack($manager->ownedGroup);

        $this->mock(SlackService::class)
            ->shouldReceive('postMessage')
            ->once()
            ->with('xoxb-test', 'C001', \Mockery::on(fn ($t) => str_contains($t, 'Compliance Gap')));

        $this->actingAs($manager)
            ->postJson('/console/admin/alerts/compliance-gap/test')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_manager_can_test_rule(): void
    {
        $manager = $this->makeManager();
        $this->makeSlack($manager->ownedGroup);
        $rule = $this->makeRule($manager->ownedGroup, ['alert_type' => 'aging', 'target_id' => 'U123']);

        $this->mock(SlackService::class)
            ->shouldReceive('postDm')
            ->once()
            ->with('xoxb-test', 'U123', \Mockery::type('string'));

        $this->actingAs($manager)
            ->postJson("/console/admin/alerts/rules/{$rule->id}/test")
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_manager_cannot_test_another_groups_rule(): void
    {
        $managerA = $this->makeManager();
        $managerB = $this->makeManager();
        $rule     = $this->makeRule($managerA->ownedGroup);

        $this->actingAs($managerB)
            ->postJson("/console/admin/alerts/rules/{$rule->id}/test")
            ->assertForbidden();
    }

    public function test_manager_can_test_digest_schedule(): void
    {
        $manager  = $this->makeManager();
        $this->makeSlack($manager->ownedGroup);
        $schedule = $this->makeDigestSchedule($manager->ownedGroup, ['target_type' => 'channel', 'target_id' => 'C001']);

        $this->mock(SlackService::class)
            ->shouldReceive('postMessage')
            ->once()
            ->with('xoxb-test', 'C001', \Mockery::type('string'));

        $this->actingAs($manager)
            ->postJson("/console/admin/alerts/digest-schedules/{$schedule->id}/test")
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_manager_can_test_digest_schedule_dm(): void
    {
        $manager  = $this->makeManager();
        $this->makeSlack($manager->ownedGroup);
        $schedule = $this->makeDigestSchedule($manager->ownedGroup, ['target_type' => 'user', 'target_id' => 'U123']);

        $this->mock(SlackService::class)
            ->shouldReceive('postDm')
            ->once()
            ->with('xoxb-test', 'U123', \Mockery::type('string'));

        $this->actingAs($manager)
            ->postJson("/console/admin/alerts/digest-schedules/{$schedule->id}/test")
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_manager_cannot_test_another_groups_digest_schedule(): void
    {
        $managerA = $this->makeManager();
        $managerB = $this->makeManager();
        $schedule = $this->makeDigestSchedule($managerA->ownedGroup);

        $this->actingAs($managerB)
            ->postJson("/console/admin/alerts/digest-schedules/{$schedule->id}/test")
            ->assertForbidden();
    }

    private function makeDigestSchedule(Group $group, array $overrides = []): SlackDigestSchedule
    {
        return SlackDigestSchedule::create(array_merge([
            'group_id'     => $group->id,
            'day_of_week'  => 1,
            'deliver_at'   => '09:00',
            'timezone'     => 'UTC',
            'target_type'  => 'channel',
            'target_id'    => 'C001',
            'target_label' => '#general',
            'active'       => true,
        ], $overrides));
    }
}
