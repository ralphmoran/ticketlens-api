<?php

namespace Tests\Feature\Owner;

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeOwner(): User
    {
        return User::factory()->create(['is_owner' => true]);
    }

    private function makeClient(array $attrs = []): User
    {
        return User::factory()->create(array_merge(['tier' => 'team', 'permissions' => 0], $attrs));
    }

    private function makeGroup(User $owner, string $name = 'Alpha Team'): Group
    {
        $group = Group::create(['name' => $name, 'owner_id' => $owner->id]);
        $group->users()->attach($owner->id);
        return $group;
    }

    // --- Index ---

    public function test_owner_can_list_teams(): void
    {
        $owner  = $this->makeOwner();
        $manager = $this->makeClient();
        $this->makeGroup($manager, 'Test Team');

        $response = $this->actingAs($owner)->get('/console/owner/teams');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Console/Owner/Teams/Index')
            ->has('teams', 1)
        );
    }

    public function test_non_owner_cannot_list_teams(): void
    {
        $user = $this->makeClient(['permissions' => 1023]);

        $response = $this->actingAs($user)->get('/console/owner/teams');

        $response->assertRedirect('/console/dashboard');
    }

    public function test_teams_index_includes_member_count(): void
    {
        $owner   = $this->makeOwner();
        $manager = $this->makeClient();
        $member  = $this->makeClient();
        $group   = $this->makeGroup($manager);
        $group->users()->attach($member->id);

        $response = $this->actingAs($owner)->get('/console/owner/teams');

        $response->assertInertia(fn ($page) => $page
            ->where('teams.0.member_count', 2)
        );
    }

    // --- Show ---

    public function test_owner_can_view_team(): void
    {
        $owner   = $this->makeOwner();
        $manager = $this->makeClient();
        $group   = $this->makeGroup($manager);

        $response = $this->actingAs($owner)->get("/console/owner/teams/{$group->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Console/Owner/Teams/Show')
            ->has('team')
            ->has('members')
        );
    }

    public function test_non_owner_cannot_view_team(): void
    {
        $nonOwner = $this->makeClient(['permissions' => 1023]);
        $manager  = $this->makeClient();
        $group    = $this->makeGroup($manager);

        $response = $this->actingAs($nonOwner)->get("/console/owner/teams/{$group->id}");

        $response->assertRedirect('/console/dashboard');
    }

    public function test_team_show_lists_members(): void
    {
        $owner   = $this->makeOwner();
        $manager = $this->makeClient();
        $member  = $this->makeClient();
        $group   = $this->makeGroup($manager);
        $group->users()->attach($member->id);

        $response = $this->actingAs($owner)->get("/console/owner/teams/{$group->id}");

        $response->assertInertia(fn ($page) => $page
            ->has('members', 2)
        );
    }

    // --- Remove member ---

    public function test_owner_can_remove_member_from_team(): void
    {
        $owner   = $this->makeOwner();
        $manager = $this->makeClient();
        $member  = $this->makeClient();
        $group   = $this->makeGroup($manager);
        $group->users()->attach($member->id);

        $response = $this->actingAs($owner)->delete(
            "/console/owner/teams/{$group->id}/members/{$member->id}"
        );

        $response->assertRedirect("/console/owner/teams/{$group->id}");
        $this->assertFalse($group->users()->where('users.id', $member->id)->exists());
    }

    public function test_cannot_remove_team_owner_from_their_group(): void
    {
        $owner   = $this->makeOwner();
        $manager = $this->makeClient();
        $group   = $this->makeGroup($manager);

        $response = $this->actingAs($owner)->delete(
            "/console/owner/teams/{$group->id}/members/{$manager->id}"
        );

        $response->assertStatus(422);
        $this->assertTrue($group->users()->where('users.id', $manager->id)->exists());
    }

    public function test_non_owner_cannot_remove_member(): void
    {
        $nonOwner = $this->makeClient(['permissions' => 1023]);
        $manager  = $this->makeClient();
        $member   = $this->makeClient();
        $group    = $this->makeGroup($manager);
        $group->users()->attach($member->id);

        $response = $this->actingAs($nonOwner)->delete(
            "/console/owner/teams/{$group->id}/members/{$member->id}"
        );

        $response->assertRedirect('/console/dashboard');
        $this->assertTrue($group->users()->where('users.id', $member->id)->exists());
    }

    public function test_remove_member_creates_audit_log(): void
    {
        $owner   = $this->makeOwner();
        $manager = $this->makeClient();
        $member  = $this->makeClient();
        $group   = $this->makeGroup($manager);
        $group->users()->attach($member->id);

        $this->actingAs($owner)->delete(
            "/console/owner/teams/{$group->id}/members/{$member->id}"
        );

        $this->assertDatabaseHas('audit_logs', [
            'actor_id'       => $owner->id,
            'target_user_id' => $member->id,
            'action'         => 'team.member_removed',
        ]);
    }
}
