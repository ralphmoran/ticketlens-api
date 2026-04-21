<?php

namespace Tests\Feature\Console\Admin;

use App\Enums\Permission;
use App\Models\Group;
use App\Models\License;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MembersControllerTest extends TestCase
{
    use RefreshDatabase;

    // --- Helpers ---

    private function makeManager(): User
    {
        // Team-tier + TeamManageMembers (128) + TeamManageSeats (256) = 127 | 384 = 511
        $manager = User::factory()->create(['tier' => 'team', 'permissions' => 511]);
        $group   = Group::create(['name' => "Team {$manager->id}", 'owner_id' => $manager->id]);
        $group->members()->attach($manager->id);

        // Active license grants 5 seats by default; unique hash per manager
        License::create([
            'user_id' => $manager->id,
            'lemon_key_hash' => hash('sha256', "manager-{$manager->id}-" . uniqid()),
            'status' => 'active', 'tier' => 'team', 'seats' => 5,
        ]);

        return $manager;
    }

    private function makeMember(Group $group, array $attrs = []): User
    {
        $member = User::factory()->create(array_merge(['tier' => 'team', 'permissions' => 127], $attrs));
        $group->members()->attach($member->id);
        return $member;
    }

    // --- Access control ---

    public function test_guest_redirected_to_login(): void
    {
        $this->get('/console/admin/members')->assertRedirect('/console/login');
    }

    public function test_plain_team_member_without_bit_cannot_access_members(): void
    {
        // Team user (127), no manager bits, NOT the owner of any group
        $user = User::factory()->create(['tier' => 'team', 'permissions' => 127]);

        $response = $this->actingAs($user)->get('/console/admin/members');

        $response->assertRedirect('/console/dashboard');
    }

    public function test_user_with_bit_but_no_owned_group_cannot_access_members(): void
    {
        // Has the bit (511) but doesn't own a group
        $user = User::factory()->create(['tier' => 'team', 'permissions' => 511]);

        $response = $this->actingAs($user)->get('/console/admin/members');

        $response->assertRedirect('/console/dashboard');
    }

    public function test_user_with_owned_group_but_no_bit_cannot_access_members(): void
    {
        // Owns a group but lost manager bits (demoted)
        $user = User::factory()->create(['tier' => 'team', 'permissions' => 127]);
        Group::create(['name' => 'Orphaned Team', 'owner_id' => $user->id]);

        $response = $this->actingAs($user)->get('/console/admin/members');

        $response->assertRedirect('/console/dashboard');
    }

    public function test_manager_can_view_members_page(): void
    {
        $manager = $this->makeManager();

        $response = $this->actingAs($manager)->get('/console/admin/members');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Console/Admin/Members'));
    }

    // --- Scoping (LOAD-BEARING INVARIANT) ---

    public function test_manager_sees_only_own_team_members(): void
    {
        $manager = $this->makeManager();
        $this->makeMember($manager->ownedGroup, ['email' => 'a@own.com']);
        $this->makeMember($manager->ownedGroup, ['email' => 'b@own.com']);

        // Another team, entirely separate
        $otherManager = $this->makeManager();
        $this->makeMember($otherManager->ownedGroup, ['email' => 'foreign@other.com']);

        $response = $this->actingAs($manager)->get('/console/admin/members');

        $response->assertInertia(fn ($page) => $page
            ->component('Console/Admin/Members')
            ->has('members', 3) // manager + 2 members of their own team
            ->where('members', fn ($members) =>
                collect($members)->every(fn ($m) => !str_contains($m['email'], 'foreign'))
            )
        );
    }

    // --- Destroy (remove member) ---

    public function test_manager_can_remove_own_member(): void
    {
        $manager = $this->makeManager();
        $member  = $this->makeMember($manager->ownedGroup);

        $response = $this->actingAs($manager)->delete("/console/admin/members/{$member->id}");

        $response->assertRedirect();
        $this->assertFalse($manager->ownedGroup->members()->where('users.id', $member->id)->exists());
    }

    public function test_removing_foreign_member_returns_404(): void
    {
        $manager      = $this->makeManager();
        $otherManager = $this->makeManager();
        $foreign      = $this->makeMember($otherManager->ownedGroup);

        $response = $this->actingAs($manager)->delete("/console/admin/members/{$foreign->id}");

        // 404 not 403 — don't leak existence of the other team
        $response->assertStatus(404);
        $this->assertTrue($otherManager->ownedGroup->members()->where('users.id', $foreign->id)->exists());
    }

    public function test_manager_cannot_remove_themselves(): void
    {
        $manager = $this->makeManager();

        $response = $this->actingAs($manager)->delete("/console/admin/members/{$manager->id}");

        $response->assertStatus(422);
        $this->assertTrue($manager->ownedGroup->members()->where('users.id', $manager->id)->exists());
    }

    public function test_remove_is_audit_logged(): void
    {
        $manager = $this->makeManager();
        $member  = $this->makeMember($manager->ownedGroup);

        $this->actingAs($manager)->delete("/console/admin/members/{$member->id}");

        $this->assertDatabaseHas('audit_logs', [
            'actor_id'       => $manager->id,
            'target_user_id' => $member->id,
            'action'         => 'team.member_removed',
        ]);
    }

    // --- Promote (transfer manager role) ---

    public function test_manager_can_promote_existing_member_to_manager(): void
    {
        $manager = $this->makeManager();
        $member  = $this->makeMember($manager->ownedGroup, ['permissions' => 127]);

        $response = $this->actingAs($manager)->post("/console/admin/members/{$member->id}/promote");

        $response->assertRedirect('/console/dashboard');

        // Group owner switched
        $this->assertSame($member->id, $manager->ownedGroup->fresh()->owner_id);
        // New manager got the bits (511 = 127 | 384)
        $this->assertSame(511, $member->fresh()->permissions);
        // Old manager lost the bits (down to 127)
        $this->assertSame(127, $manager->fresh()->permissions);
    }

    public function test_invariant_team_always_has_exactly_one_manager(): void
    {
        $manager = $this->makeManager();
        $member  = $this->makeMember($manager->ownedGroup);

        $this->actingAs($manager)->post("/console/admin/members/{$member->id}/promote");

        $group = $manager->ownedGroup()->first() ?? Group::where('owner_id', $member->id)->first();
        $this->assertNotNull($group);
        $this->assertNotNull($group->owner_id);
        $this->assertTrue($member->fresh()->isTeamManager());
        $this->assertFalse($manager->fresh()->isTeamManager());
    }

    public function test_promoting_foreign_member_returns_404(): void
    {
        $manager      = $this->makeManager();
        $otherManager = $this->makeManager();
        $foreign      = $this->makeMember($otherManager->ownedGroup);

        $response = $this->actingAs($manager)->post("/console/admin/members/{$foreign->id}/promote");

        $response->assertStatus(404);
        // Original managers still own their groups
        $this->assertTrue($manager->fresh()->isTeamManager());
        $this->assertTrue($otherManager->fresh()->isTeamManager());
    }

    public function test_cannot_promote_self(): void
    {
        $manager = $this->makeManager();

        $response = $this->actingAs($manager)->post("/console/admin/members/{$manager->id}/promote");

        $response->assertStatus(422);
    }
}
