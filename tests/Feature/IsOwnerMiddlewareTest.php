<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class IsOwnerMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get('/console/owner/dashboard');

        $response->assertRedirect('/console/login');
    }

    public function test_non_owner_is_redirected_to_dashboard(): void
    {
        $user = User::factory()->create(['is_owner' => false, 'permissions' => 1023]);

        $response = $this->actingAs($user)->get('/console/owner/dashboard');

        $response->assertRedirect('/console/dashboard');
    }

    public function test_owner_can_access_owner_routes(): void
    {
        $user = User::factory()->create(['is_owner' => true]);

        $response = $this->actingAs($user)->get('/console/owner/dashboard');

        $response->assertStatus(200);
    }
}
