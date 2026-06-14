<?php

namespace Tests\Feature\Console;

use App\Models\User;
use App\Services\TierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── Lock: showLogin ──────────────────────────────────────────────────────

    public function test_login_page_renders_for_guests(): void
    {
        $this->get('/console/login')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page->component('Auth/Login'));
    }

    public function test_authenticated_user_cannot_reach_login_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/console/login')
            ->assertRedirect();
    }

    // ── Lock: login ──────────────────────────────────────────────────────────

    public function test_valid_credentials_log_in_and_redirect_to_dashboard(): void
    {
        $user = User::factory()->create();

        $this->post('/console/login', [
            'email'    => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('console.dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_invalid_credentials_redirect_back_with_email_error(): void
    {
        User::factory()->create(['email' => 'user@example.com']);

        $this->post('/console/login', [
            'email'    => 'user@example.com',
            'password' => 'wrong-password',
        ])->assertRedirect();

        $this->assertGuest();
    }

    // ── Lock: logout ─────────────────────────────────────────────────────────

    public function test_logout_clears_session_and_redirects_to_login(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/console/logout')
            ->assertRedirect('/console/login');

        $this->assertGuest();
    }

    // ── Red: register ─────────────────────────────────────────────────────────

    public function test_successful_registration_creates_user_and_redirects_to_verification_notice(): void
    {
        $this->post('/console/register', [
            'name'                  => 'Jane Smith',
            'email'                 => 'jane@example.com',
            'password'              => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertRedirect('/console/verify-email');

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'email'             => 'jane@example.com',
            'tier'              => 'free',
            'email_verified_at' => null,
        ]);
    }

    public function test_registration_sends_verification_email(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $this->post('/console/register', [
            'name'                  => 'Jane Smith',
            'email'                 => 'jane@example.com',
            'password'              => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $user = \App\Models\User::where('email', 'jane@example.com')->firstOrFail();

        \Illuminate\Support\Facades\Notification::assertSentTo(
            $user,
            \Illuminate\Auth\Notifications\VerifyEmail::class,
        );
    }

    public function test_unverified_user_is_redirected_to_verification_notice_on_login(): void
    {
        $user = User::factory()->unverified()->create();

        $this->post('/console/login', [
            'email'    => $user->email,
            'password' => 'password',
        ])->assertRedirect('/console/verify-email');

        $this->assertAuthenticatedAs($user);
    }

    public function test_duplicate_email_redirects_back_without_creating_user(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->post('/console/register', [
            'name'                  => 'Jane Smith',
            'email'                 => 'taken@example.com',
            'password'              => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertRedirect();

        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_short_password_redirects_back_without_creating_user(): void
    {
        $this->post('/console/register', [
            'name'                  => 'Jane Smith',
            'email'                 => 'jane@example.com',
            'password'              => 'short',
            'password_confirmation' => 'short',
        ])->assertRedirect();

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'jane@example.com']);
    }

    public function test_mismatched_password_confirmation_redirects_back(): void
    {
        $this->post('/console/register', [
            'name'                  => 'Jane Smith',
            'email'                 => 'jane@example.com',
            'password'              => 'secret123',
            'password_confirmation' => 'different1',
        ])->assertRedirect();

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'jane@example.com']);
    }

    public function test_missing_name_redirects_back_without_creating_user(): void
    {
        $this->post('/console/register', [
            'email'                 => 'jane@example.com',
            'password'              => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertRedirect();

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'jane@example.com']);
    }

    public function test_authenticated_user_cannot_register(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/console/register', [
                'name'                  => 'Another User',
                'email'                 => 'another@example.com',
                'password'              => 'secret123',
                'password_confirmation' => 'secret123',
            ])->assertRedirect();

        $this->assertDatabaseMissing('users', ['email' => 'another@example.com']);
    }

    public function test_tier_sync_failure_rolls_back_user_row(): void
    {
        $this->mock(TierService::class)
            ->shouldReceive('syncUser')
            ->once()
            ->andThrow(new \RuntimeException('DB failure'));

        $this->post('/console/register', [
            'name'                  => 'Jane Smith',
            'email'                 => 'jane@example.com',
            'password'              => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertStatus(500);

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'jane@example.com']);
    }

    public function test_register_route_has_ip_and_email_rate_limiting(): void
    {
        $route = app('router')->getRoutes()->getByName('console.register');

        $this->assertContains('throttle:3,1', $route->middleware());
        $this->assertContains('throttle:register-by-email', $route->middleware());
    }
}
