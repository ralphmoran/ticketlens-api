<?php

namespace Tests\Feature\Console\Middleware;

use App\Enums\Permission;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnsureTeamLeadTest extends TestCase
{
    use RefreshDatabase;

    // --- Helpers ---

    private function makeManager(): User
    {
        $manager = User::factory()->create(['tier' => 'team', 'permissions' => 511]);
        $group   = Group::create(['name' => "Team {$manager->id}", 'owner_id' => $manager->id]);
        $group->members()->attach($manager->id);
        return $manager;
    }

    private function makeLead(Group $group): User
    {
        $lead = User::factory()->create([
            'tier'        => 'team',
            'permissions' => Permission::team() | Permission::TeamViewHealth->value,
        ]);
        $group->members()->attach($lead->id);
        return $lead;
    }

    private function makeMember(Group $group): User
    {
        $member = User::factory()->create(['tier' => 'team', 'permissions' => Permission::team()]);
        $group->members()->attach($member->id);
        return $member;
    }

    // --- LOCK: existing invariants ---

    public function test_team_permission_bitmask_is_639(): void
    {
        $this->assertSame(639, Permission::team());
    }

    public function test_manager_mask_is_384(): void
    {
        $this->assertSame(384, Permission::teamManagerMask());
    }

    public function test_team_view_health_bit_is_1024(): void
    {
        $this->assertSame(1024, Permission::TeamViewHealth->value);
    }

    public function test_team_view_health_not_in_team_preset(): void
    {
        $this->assertSame(0, Permission::team() & Permission::TeamViewHealth->value);
    }

    // --- Access control ---

    public function test_guest_redirected_to_login(): void
    {
        $this->get('/console/admin/team-health')->assertRedirect('/console/login');
    }

    public function test_plain_team_member_cannot_access_team_health(): void
    {
        $manager = $this->makeManager();
        $member  = $this->makeMember($manager->ownedGroup);

        $this->actingAs($member)->get('/console/admin/team-health')->assertRedirect('/console/dashboard');
    }

    public function test_lead_can_access_team_health(): void
    {
        $manager = $this->makeManager();
        $lead    = $this->makeLead($manager->ownedGroup);

        $this->actingAs($lead)->get('/console/admin/team-health')->assertOk();
    }

    public function test_manager_can_access_team_health(): void
    {
        $manager = $this->makeManager();

        $this->actingAs($manager)->get('/console/admin/team-health')->assertOk();
    }

    public function test_lead_cannot_access_process_metrics(): void
    {
        $manager = $this->makeManager();
        $lead    = $this->makeLead($manager->ownedGroup);

        $this->actingAs($lead)->get('/console/admin/process-metrics')->assertRedirect('/console/dashboard');
    }

    public function test_lead_cannot_access_members(): void
    {
        $manager = $this->makeManager();
        $lead    = $this->makeLead($manager->ownedGroup);

        $this->actingAs($lead)->get('/console/admin/members')->assertRedirect('/console/dashboard');
    }

    public function test_owner_can_access_team_health_without_any_bits(): void
    {
        $owner = User::factory()->create(['tier' => 'owner', 'is_owner' => true, 'permissions' => 0]);

        $this->actingAs($owner)->get('/console/admin/team-health')->assertOk();
    }

    public function test_lead_with_no_group_gets_403_from_controller(): void
    {
        // Middleware passes, but controller aborts — a lead must belong to a group.
        $user = User::factory()->create([
            'tier'        => 'team',
            'permissions' => Permission::team() | Permission::TeamViewHealth->value,
        ]);

        // No group attached — middleware passes, controller enforces group membership.
        $this->actingAs($user)->get('/console/admin/team-health')->assertStatus(403);
    }
}
