<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── show ────────────────────────────────────────────────────────────────

    public function test_show_renders_set_password_form(): void
    {
        $user  = User::factory()->create(['email' => 'invited@example.com']);
        $token = Password::broker()->createToken($user);

        $this->get("/console/reset-password/{$token}?email=invited%40example.com")
            ->assertStatus(200)
            ->assertViewIs('console.set-password')
            ->assertViewHas('token', $token)
            ->assertViewHas('email', 'invited@example.com')
            ->assertViewHas('authWarning', null);
    }

    public function test_show_renders_form_without_email_param(): void
    {
        $user  = User::factory()->create(['email' => 'noemail@example.com']);
        $token = Password::broker()->createToken($user);

        $this->get("/console/reset-password/{$token}")
            ->assertStatus(200)
            ->assertViewIs('console.set-password')
            ->assertViewHas('token', $token)
            ->assertViewHas('email', '');
    }

    public function test_show_renders_warning_for_authenticated_user(): void
    {
        $user  = User::factory()->create();
        $token = Password::broker()->createToken($user);

        $this->actingAs($user)
            ->get("/console/reset-password/{$token}?email=" . urlencode($user->email))
            ->assertStatus(200)
            ->assertViewIs('console.set-password')
            ->assertViewHas('authWarning', $user->email);
    }

    public function test_reset_is_blocked_for_authenticated_user(): void
    {
        $owner  = User::factory()->create(['email' => 'owner@example.com', 'email_verified_at' => now()]);
        $target = User::factory()->create(['email' => 'target@example.com', 'email_verified_at' => now()]);
        $token  = Password::broker()->createToken($target);

        $this->actingAs($owner)
            ->post('/console/reset-password', [
                'token'                 => $token,
                'email'                 => 'target@example.com',
                'password'              => 'HackedPassword1!',
                'password_confirmation' => 'HackedPassword1!',
            ])
            ->assertRedirect();

        $target->refresh();
        $this->assertFalse(Hash::check('HackedPassword1!', $target->password));
        $this->assertAuthenticatedAs($owner);
    }

    // ── reset ────────────────────────────────────────────────────────────────

    public function test_reset_sets_password_and_logs_user_in_and_redirects_to_dashboard(): void
    {
        $user  = User::factory()->create(['email' => 'invited@example.com', 'email_verified_at' => now()]);
        $token = Password::broker()->createToken($user);

        $response = $this->post('/console/reset-password', [
            'token'                 => $token,
            'email'                 => 'invited@example.com',
            'password'              => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ]);

        $response->assertRedirect(route('console.dashboard'));

        $user->refresh();
        $this->assertTrue(Hash::check('NewPassword1!', $user->password));
        $this->assertAuthenticatedAs($user);
    }

    public function test_reset_fails_with_invalid_token_and_returns_error(): void
    {
        $user = User::factory()->create(['email' => 'invited@example.com']);

        $this->post('/console/reset-password', [
            'token'                 => 'invalid-token-that-does-not-exist',
            'email'                 => 'invited@example.com',
            'password'              => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_reset_fails_when_passwords_do_not_match(): void
    {
        $user  = User::factory()->create(['email' => 'invited@example.com']);
        $token = Password::broker()->createToken($user);

        $this->post('/console/reset-password', [
            'token'                 => $token,
            'email'                 => 'invited@example.com',
            'password'              => 'NewPassword1!',
            'password_confirmation' => 'DifferentPassword2!',
        ])->assertSessionHasErrors('password');
    }

    public function test_reset_requires_minimum_password_length(): void
    {
        $user  = User::factory()->create(['email' => 'invited@example.com']);
        $token = Password::broker()->createToken($user);

        $this->post('/console/reset-password', [
            'token'                 => $token,
            'email'                 => 'invited@example.com',
            'password'              => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');
    }

    public function test_reset_token_is_single_use(): void
    {
        $user  = User::factory()->create(['email' => 'invited@example.com', 'email_verified_at' => now()]);
        $token = Password::broker()->createToken($user);

        $this->post('/console/reset-password', [
            'token'                 => $token,
            'email'                 => 'invited@example.com',
            'password'              => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ])->assertRedirect(route('console.dashboard'));

        // Broker deletes the token row on success — single-use enforced at DB level.
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'invited@example.com']);
    }
}
