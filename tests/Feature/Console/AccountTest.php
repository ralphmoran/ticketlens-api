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

    public function test_account_page_passes_key_presence_flags(): void
    {
        $user = User::factory()->create([
            'tier'          => 'pro',
            'permissions'   => 71,
            'anthropic_key' => 'sk-ant-test-key',
            'openai_key'    => null,
        ]);

        $response = $this->actingAs($user)->get('/console/account');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Console/Account')
            ->where('has_anthropic_key', true)
            ->where('has_openai_key', false)
        );
    }

    public function test_user_can_save_anthropic_key(): void
    {
        $user = User::factory()->create(['tier' => 'pro', 'permissions' => 71]);

        $response = $this->actingAs($user)->post('/console/account/keys', [
            'anthropic_key' => 'sk-ant-test-key-123',
            'openai_key'    => '',
        ]);

        $response->assertRedirect();

        $user->refresh();
        $this->assertSame('sk-ant-test-key-123', $user->anthropic_key);
        $this->assertNull($user->openai_key);
    }

    public function test_user_can_save_openai_key(): void
    {
        $user = User::factory()->create(['tier' => 'pro', 'permissions' => 71]);

        $response = $this->actingAs($user)->post('/console/account/keys', [
            'anthropic_key' => '',
            'openai_key'    => 'sk-openai-test-key-456',
        ]);

        $response->assertRedirect();

        $user->refresh();
        $this->assertNull($user->anthropic_key);
        $this->assertSame('sk-openai-test-key-456', $user->openai_key);
    }

    public function test_user_can_clear_api_keys(): void
    {
        $user = User::factory()->create([
            'tier'          => 'pro',
            'permissions'   => 71,
            'anthropic_key' => 'sk-ant-existing',
            'openai_key'    => 'sk-openai-existing',
        ]);

        $response = $this->actingAs($user)->post('/console/account/keys', [
            'anthropic_key' => '',
            'openai_key'    => '',
        ]);

        $response->assertRedirect();

        $user->refresh();
        $this->assertNull($user->anthropic_key);
        $this->assertNull($user->openai_key);
    }
}
