<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class UpgradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get('/console/upgrade');

        $response->assertRedirect('/console/login');
    }

    public function test_authenticated_user_sees_upgrade_page(): void
    {
        $user = User::factory()->create(['tier' => 'free', 'permissions' => 64]);

        $response = $this->actingAs($user)->get('/console/upgrade');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Console/Upgrade')
            ->where('required_tier', 'pro')
            ->where('current_tier', 'free')
        );
    }

    public function test_upgrade_page_accepts_tier_query_param(): void
    {
        $user = User::factory()->create(['tier' => 'pro', 'permissions' => 71]);

        $response = $this->actingAs($user)->get('/console/upgrade?tier=team');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Console/Upgrade')
            ->where('required_tier', 'team')
            ->where('current_tier', 'pro')
        );
    }

    public function test_permission_denied_redirects_authenticated_user_to_upgrade(): void
    {
        $user = User::factory()->create(['tier' => 'free', 'permissions' => 64]);

        // Free user has no Schedules permission — plain GET should redirect to upgrade
        $response = $this->actingAs($user)->get('/console/schedules');

        $response->assertRedirect('/console/upgrade');
    }
}
