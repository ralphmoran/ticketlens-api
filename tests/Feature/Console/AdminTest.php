<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    // Team-manager access = free(64) | TeamManageSeats(256) = 320
    private function makeAdminUser(): User
    {
        return User::factory()->create(['tier' => 'enterprise', 'permissions' => 320]);
    }

    public function test_guest_is_redirected_to_login_for_licenses(): void
    {
        $response = $this->get('/console/admin/licenses');

        $response->assertRedirect('/console/login');
    }

    public function test_non_admin_gets_403_for_licenses(): void
    {
        // Team (127) has no TeamManageSeats bit (256) without explicit manager promotion
        $user = User::factory()->create(['tier' => 'team', 'permissions' => 127]);

        $response = $this->actingAs($user)->getJson('/console/admin/licenses');

        $response->assertStatus(403);
    }

    public function test_admin_sees_licenses_page(): void
    {
        $user = $this->makeAdminUser();

        $response = $this->actingAs($user)->get('/console/admin/licenses');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Console/Admin/Licenses')
        );
    }

    public function test_admin_clients_route_is_removed(): void
    {
        $user = $this->makeAdminUser();

        $response = $this->actingAs($user)->get('/console/admin/clients');

        $response->assertStatus(404);
    }

    public function test_admin_revenue_route_is_removed(): void
    {
        $user = $this->makeAdminUser();

        $response = $this->actingAs($user)->get('/console/admin/revenue');

        $response->assertStatus(404);
    }
}
