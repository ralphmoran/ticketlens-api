<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get('/console/account');

        $response->assertRedirect('/console/login');
    }

    public function test_authenticated_user_sees_account_page(): void
    {
        $user = User::factory()->create([
            'name'        => 'Test User',
            'email'       => 'test@example.com',
            'tier'        => 'pro',
            'permissions' => 71,
        ]);

        $response = $this->actingAs($user)->get('/console/account');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Console/Account')
            ->where('account.name', 'Test User')
            ->where('account.email', 'test@example.com')
            ->where('account.tier', 'pro')
            ->where('account.license', null)
        );
    }

    public function test_account_page_renders_correct_component(): void
    {
        $user = User::factory()->create(['tier' => 'free', 'permissions' => 64]);

        $response = $this->actingAs($user)->get('/console/account');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Console/Account'));
    }
}
