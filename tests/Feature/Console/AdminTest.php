<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    // Admin = free(64) | AdminUsers(128) | AdminLicenses(256) | AdminRevenue(512) = 960
    private function makeAdminUser(): User
    {
        return User::factory()->create(['tier' => 'enterprise', 'permissions' => 960]);
    }

    public function test_guest_is_redirected_to_login_for_clients(): void
    {
        $response = $this->get('/console/admin/clients');

        $response->assertRedirect('/console/login');
    }

    public function test_non_admin_gets_403_for_clients(): void
    {
        // Team (127) has no AdminUsers bit (128)
        $user = User::factory()->create(['tier' => 'team', 'permissions' => 127]);

        $response = $this->actingAs($user)->getJson('/console/admin/clients');

        $response->assertStatus(403);
    }

    public function test_admin_sees_clients_page(): void
    {
        $user = $this->makeAdminUser();

        $response = $this->actingAs($user)->get('/console/admin/clients');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Console/Admin/Clients')
        );
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

    public function test_admin_sees_revenue_page(): void
    {
        $user = $this->makeAdminUser();

        $response = $this->actingAs($user)->get('/console/admin/revenue');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Console/Admin/Revenue')
        );
    }
}
