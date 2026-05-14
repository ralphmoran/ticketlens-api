<?php

namespace Tests\Feature\Console\Admin;

use App\Models\AlertSetting;
use App\Models\CustomAlertRule;
use App\Models\Group;
use App\Models\License;
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
                ->has('rules', 1)
                ->where('rules.0.alert_type', 'aging')
                ->where('rules.0.target_id', 'U123')
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
}
