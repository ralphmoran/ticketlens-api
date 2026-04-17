<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

class SuspendedPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_suspended_page_is_accessible_to_guests(): void
    {
        $response = $this->get('/console/suspended');

        $response->assertStatus(200);
    }

    public function test_suspended_user_is_rejected_on_login(): void
    {
        User::factory()->create([
            'email'        => 'suspended@test.com',
            'password'     => bcrypt('password'),
            'suspended_at' => now(),
        ]);

        $response = $this->post('/console/login', [
            'email'    => 'suspended@test.com',
            'password' => 'password',
        ]);

        $response->assertRedirect('/console/suspended');
        $this->assertFalse(Auth::check());
    }

    public function test_active_user_is_not_redirected_to_suspended(): void
    {
        User::factory()->create([
            'email'        => 'active@test.com',
            'password'     => bcrypt('password'),
            'suspended_at' => null,
        ]);

        $response = $this->post('/console/login', [
            'email'    => 'active@test.com',
            'password' => 'password',
        ]);

        $response->assertRedirect('/console/dashboard');
    }
}
