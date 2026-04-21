<?php

namespace Tests\Feature\Console\Admin;

use App\Models\Group;
use App\Models\License;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeatsControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeManagerWithLicense(int $seats = 5): User
    {
        $manager = User::factory()->create(['tier' => 'team', 'permissions' => 511]);
        $group   = Group::create(['name' => 'Team', 'owner_id' => $manager->id]);
        $group->members()->attach($manager->id);

        License::create([
            'user_id' => $manager->id,
            'lemon_key_hash' => str_repeat('s', 64),
            'status' => 'active', 'tier' => 'team', 'seats' => $seats,
        ]);

        return $manager;
    }

    public function test_guest_is_redirected(): void
    {
        $this->get('/console/admin/seats')->assertRedirect('/console/login');
    }

    public function test_non_manager_redirected_to_dashboard(): void
    {
        $user = User::factory()->create(['tier' => 'team', 'permissions' => 127]);

        $this->actingAs($user)->get('/console/admin/seats')->assertRedirect('/console/dashboard');
    }

    public function test_manager_sees_seat_usage(): void
    {
        $manager = $this->makeManagerWithLicense(seats: 10);

        $response = $this->actingAs($manager)->get('/console/admin/seats');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Console/Admin/Seats')
            ->where('license.seats', 10)
            ->where('seats_used', 1) // manager only
        );
    }

    public function test_seat_usage_counts_all_group_members(): void
    {
        $manager = $this->makeManagerWithLicense(seats: 5);
        $group   = $manager->ownedGroup;

        // Add 2 more members
        $m1 = User::factory()->create(['tier' => 'team']);
        $m2 = User::factory()->create(['tier' => 'team']);
        $group->members()->attach([$m1->id, $m2->id]);

        $response = $this->actingAs($manager)->get('/console/admin/seats');

        $response->assertInertia(fn ($page) => $page
            ->where('seats_used', 3)
            ->where('license.seats', 5)
        );
    }
}
