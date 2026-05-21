<?php

namespace Tests\Feature\Console\Admin;

use App\Models\Group;
use App\Models\License;
use App\Models\SlackDigestSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DigestsTest extends TestCase
{
    use RefreshDatabase;

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

    private function makeSchedule(Group $group, array $overrides = []): SlackDigestSchedule
    {
        return SlackDigestSchedule::create(array_merge([
            'group_id'    => $group->id,
            'day_of_week' => 1,
            'deliver_at'  => '09:00',
            'timezone'    => 'UTC',
            'target_type' => 'channel',
            'target_id'   => 'C001',
            'target_label'=> '#general',
            'active'      => true,
        ], $overrides));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/console/admin/digests')
            ->assertRedirect('/console/login');
    }

    public function test_non_manager_is_redirected_to_dashboard(): void
    {
        $member = User::factory()->create(['tier' => 'team', 'permissions' => 127]);

        $this->actingAs($member)
            ->get('/console/admin/digests')
            ->assertRedirect('/console/dashboard');
    }

    public function test_manager_sees_digests_page(): void
    {
        $manager = $this->makeManager();
        $group   = $manager->ownedGroup;

        $this->makeSchedule($group, ['target_label' => '#team-digests']);

        $response = $this->actingAs($manager)
            ->get('/console/admin/digests');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Console/Admin/Digests')
            ->has('digestSchedules.data', 1)
            ->where('digestSchedules.data.0.target_label', '#team-digests')
            ->where('group.id', $group->id)
        );
    }

    public function test_manager_only_sees_own_groups_schedules(): void
    {
        $manager  = $this->makeManager();
        $group1   = $manager->ownedGroup;
        $other    = User::factory()->create();
        $group2   = Group::create(['name' => 'Other Team', 'owner_id' => $other->id]);

        $this->makeSchedule($group1);
        $this->makeSchedule($group2);

        $this->actingAs($manager)
            ->get('/console/admin/digests')
            ->assertInertia(fn ($page) => $page
                ->has('digestSchedules.data', 1)
            );
    }

    public function test_owner_can_view_any_groups_digests(): void
    {
        $owner  = $this->makeOwner();
        $client = User::factory()->create();
        $group  = Group::create(['name' => 'Client Team', 'owner_id' => $client->id]);

        $this->makeSchedule($group);

        $this->actingAs($owner)
            ->get("/console/admin/digests?group_id={$group->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Console/Admin/Digests')
                ->has('digestSchedules.data', 1)
                ->where('group.id', $group->id)
            );
    }

    public function test_manager_cannot_see_another_groups_schedules_via_group_id_param(): void
    {
        $manager = $this->makeManager();
        $other   = $this->makeManager();
        $this->makeSchedule($other->ownedGroup);

        $this->actingAs($manager)
            ->get("/console/admin/digests?group_id={$other->ownedGroup->id}")
            ->assertInertia(fn ($page) => $page
                ->has('digestSchedules.data', 0)
            );
    }

    public function test_owner_without_group_id_sees_empty_state(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)
            ->get('/console/admin/digests')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Console/Admin/Digests')
                ->where('group', null)
                ->has('digestSchedules.data', 0)
            );
    }
}
