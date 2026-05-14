<?php

namespace Tests\Feature\Console\Admin;

use App\Models\AlertSetting;
use App\Models\Group;
use App\Models\License;
use App\Models\User;
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

    public function test_index_returns_false_defaults_when_no_settings_exist(): void
    {
        $manager = $this->makeManager();

        $response = $this->actingAs($manager)->get('/console/admin/alerts');

        $response->assertInertia(fn ($page) => $page
            ->component('Console/Admin/Alerts')
            ->where('settings.needs_response_enabled', false)
            ->where('settings.aging_enabled', false)
        );
    }

    public function test_index_returns_existing_settings(): void
    {
        $manager = $this->makeManager();
        $group   = $manager->ownedGroup;
        AlertSetting::create([
            'group_id'               => $group->id,
            'needs_response_enabled' => true,
            'aging_enabled'          => false,
        ]);

        $response = $this->actingAs($manager)->get('/console/admin/alerts');

        $response->assertInertia(fn ($page) => $page
            ->where('settings.needs_response_enabled', true)
            ->where('settings.aging_enabled', false)
        );
    }

    // ── Save ──────────────────────────────────────────────────────────────────

    public function test_manager_can_save_alert_settings(): void
    {
        $manager = $this->makeManager();

        $this->actingAs($manager)->post('/console/admin/alerts', [
            'needs_response_enabled' => true,
            'aging_enabled'          => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('alert_settings', [
            'group_id'               => $manager->ownedGroup->id,
            'needs_response_enabled' => true,
            'aging_enabled'          => true,
        ]);
    }

    public function test_save_upserts_on_second_call(): void
    {
        $manager = $this->makeManager();
        $group   = $manager->ownedGroup;

        $this->actingAs($manager)->post('/console/admin/alerts', [
            'needs_response_enabled' => true,
            'aging_enabled'          => false,
        ]);
        $this->actingAs($manager)->post('/console/admin/alerts', [
            'needs_response_enabled' => false,
            'aging_enabled'          => true,
        ]);

        $this->assertSame(1, AlertSetting::where('group_id', $group->id)->count());
        $this->assertDatabaseHas('alert_settings', [
            'group_id'               => $group->id,
            'needs_response_enabled' => false,
            'aging_enabled'          => true,
        ]);
    }

    public function test_save_requires_boolean_fields(): void
    {
        $manager = $this->makeManager();

        $this->actingAs($manager)->post('/console/admin/alerts', [
            'needs_response_enabled' => 'not-a-bool',
            'aging_enabled'          => true,
        ])->assertSessionHasErrors(['needs_response_enabled']);
    }

    public function test_owner_can_save_alerts_for_any_group(): void
    {
        $owner   = $this->makeOwner();
        $manager = $this->makeManager();
        $group   = $manager->ownedGroup;

        $this->actingAs($owner)->post("/console/owner/alerts?group_id={$group->id}", [
            'needs_response_enabled' => true,
            'aging_enabled'          => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('alert_settings', ['group_id' => $group->id]);
    }

    public function test_plain_member_cannot_save_settings(): void
    {
        $member = User::factory()->create(['tier' => 'team', 'permissions' => 127]);

        $this->actingAs($member)->post('/console/admin/alerts', [
            'needs_response_enabled' => true,
            'aging_enabled'          => false,
        ])->assertRedirect('/console/dashboard');
    }
}
