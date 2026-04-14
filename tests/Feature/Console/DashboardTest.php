<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get('/console/dashboard');

        $response->assertRedirect('/console/login');
    }

    public function test_authenticated_user_sees_dashboard(): void
    {
        $user = User::factory()->create(['tier' => 'pro', 'permissions' => 71]);

        $response = $this->actingAs($user)->get('/console/dashboard');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Console/Dashboard'));
    }

    public function test_console_root_redirects_to_dashboard(): void
    {
        $user = User::factory()->create(['tier' => 'free', 'permissions' => 64]);

        $response = $this->actingAs($user)->get('/console');

        $response->assertRedirect('/console/dashboard');
    }

    public function test_login_redirects_to_dashboard(): void
    {
        $user = User::factory()->create([
            'email'    => 'test@example.com',
            'password' => bcrypt('password'),
            'tier'     => 'pro',
            'permissions' => 71,
        ]);

        $response = $this->post('/console/login', [
            'email'    => 'test@example.com',
            'password' => 'password',
        ]);

        $response->assertRedirect('/console/dashboard');
    }
}
