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

    public function test_account_page_does_not_expose_ai_key_props(): void
    {
        $user = User::factory()->create([
            'tier'          => 'pro',
            'permissions'   => 71,
            'anthropic_key' => 'sk-ant-test-key',
        ]);

        $response = $this->actingAs($user)->get('/console/account');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Console/Account')
            ->missing('has_anthropic_key')
            ->missing('has_openai_key')
        );
    }

    public function test_account_keys_route_no_longer_exists(): void
    {
        $user = User::factory()->create(['tier' => 'pro', 'permissions' => 71]);

        $response = $this->actingAs($user)->post('/console/account/keys', [
            'anthropic_key' => 'sk-ant-test',
            'openai_key'    => '',
        ]);

        $response->assertStatus(404); // Route removed — no longer registered
    }

    public function test_account_page_does_not_expose_cli_token_prop(): void
    {
        $user = User::factory()->create(['tier' => 'pro', 'permissions' => 71]);

        $this->actingAs($user)->get('/console/account')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Console/Account')
                ->missing('cli_token')
            );
    }

    public function test_flash_success_is_shared_with_inertia(): void
    {
        $user = User::factory()->create(['tier' => 'pro', 'permissions' => 71]);
        \App\Models\CliToken::create([
            'user_id'    => $user->id,
            'name'       => 'CLI Token',
            'token_hash' => \App\Models\CliToken::hashToken('tl_existingtoken1234567890abcdefg'),
        ]);

        // revokeCliToken() already sets ->with('success', ...) — this was previously
        // dropped by HandleInertiaRequests never forwarding it into the shared prop.
        $this->actingAs($user)->delete('/console/account/cli-token');

        $this->actingAs($user)->get('/console/account')
            ->assertInertia(fn ($page) => $page
                ->where('flash.success', 'CLI access token revoked.')
            );
    }

    public function test_account_page_exposes_phone(): void
    {
        $user = User::factory()->create(['tier' => 'pro', 'permissions' => 71, 'phone' => '+1-555-0100']);

        $this->actingAs($user)->get('/console/account')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Console/Account')
                ->where('account.phone', '+1-555-0100')
            );
    }

    public function test_update_saves_name_and_phone(): void
    {
        $user = User::factory()->create(['name' => 'Old Name', 'phone' => null]);

        $response = $this->actingAs($user)->patch('/console/account', [
            'name'  => 'New Name',
            'phone' => '+1-555-0199',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Profile updated.');
        $this->assertEquals('New Name', $user->fresh()->name);
        $this->assertEquals('+1-555-0199', $user->fresh()->phone);
    }

    public function test_update_accepts_null_phone(): void
    {
        $user = User::factory()->create(['phone' => '+1-555-0100']);

        $response = $this->actingAs($user)->patch('/console/account', [
            'name'  => $user->name,
            'phone' => null,
        ]);

        $response->assertRedirect();
        $this->assertNull($user->fresh()->phone);
    }

    public function test_update_rejects_missing_name(): void
    {
        $user = User::factory()->create(['name' => 'Keep Me']);

        $response = $this->actingAs($user)->patch('/console/account', ['phone' => '+1-555-0100']);

        $response->assertSessionHasErrors('name');
        $this->assertEquals('Keep Me', $user->fresh()->name);
    }

    public function test_update_never_touches_ai_keys_even_if_submitted(): void
    {
        $user = User::factory()->create(['name' => 'Original Name', 'anthropic_key' => 'sk-ant-original']);

        $response = $this->actingAs($user)->patch('/console/account', [
            'name'          => 'Updated Name',
            'phone'         => null,
            'anthropic_key' => 'sk-ant-injected',
        ]);

        // Prove the update actually ran (not a vacuous pass from a 404/no-op).
        $response->assertRedirect();
        $this->assertEquals('Updated Name', $user->fresh()->name);
        $this->assertEquals('sk-ant-original', $user->fresh()->anthropic_key);
    }

    public function test_update_password_succeeds_with_correct_current_password(): void
    {
        $user = User::factory()->create(['password' => \Illuminate\Support\Facades\Hash::make('old-password-123')]);

        $response = $this->actingAs($user)->patch('/console/account/password', [
            'current_password'          => 'old-password-123',
            'password'                  => 'new-password-456',
            'password_confirmation'     => 'new-password-456',
        ]);

        // Specifically the account page, not wherever back() falls back to —
        // regression guard: logoutOtherDevices() migrates the session, which
        // drops Laravel's tracked "previous URL," so a bare back() previously
        // landed the user on the dashboard with the success message invisible
        // (Dashboard.vue doesn't render flash.success). Found via manual testing.
        $response->assertRedirect('/console/account');
        $response->assertSessionHas('success', 'Password changed.');
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('new-password-456', $user->fresh()->password));
    }

    public function test_update_password_rejects_wrong_current_password(): void
    {
        $user = User::factory()->create(['password' => \Illuminate\Support\Facades\Hash::make('old-password-123')]);

        $response = $this->actingAs($user)->patch('/console/account/password', [
            'current_password'      => 'totally-wrong',
            'password'               => 'new-password-456',
            'password_confirmation'  => 'new-password-456',
        ]);

        $response->assertSessionHasErrors('current_password');
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('old-password-123', $user->fresh()->password));
    }

    public function test_update_password_keeps_the_current_session_authenticated(): void
    {
        $user = User::factory()->create(['password' => \Illuminate\Support\Facades\Hash::make('old-password-123')]);

        // logoutOtherDevices() must not sign out the session that performed the change.
        $this->actingAs($user)->patch('/console/account/password', [
            'current_password'      => 'old-password-123',
            'password'               => 'new-password-456',
            'password_confirmation'  => 'new-password-456',
        ]);

        $this->actingAs($user)->get('/console/account')->assertStatus(200);
        $this->assertAuthenticatedAs($user);
    }

    public function test_update_password_rejects_confirmation_mismatch(): void
    {
        $user = User::factory()->create(['password' => \Illuminate\Support\Facades\Hash::make('old-password-123')]);

        $response = $this->actingAs($user)->patch('/console/account/password', [
            'current_password'      => 'old-password-123',
            'password'               => 'new-password-456',
            'password_confirmation'  => 'does-not-match',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('old-password-123', $user->fresh()->password));
    }
}
