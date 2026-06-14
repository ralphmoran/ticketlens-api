<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── Lock: existing verified users still reach dashboard ──────────────────

    public function test_verified_user_can_access_console_dashboard(): void
    {
        $user = User::factory()->create(); // factory sets email_verified_at = now()

        $this->actingAs($user)
            ->get('/console/dashboard')
            ->assertStatus(200);
    }

    // ── notice ───────────────────────────────────────────────────────────────

    public function test_notice_page_renders_for_unverified_authenticated_user(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get('/console/verify-email')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page->component('Auth/VerifyEmail'));
    }

    public function test_notice_page_redirects_verified_user_to_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/console/verify-email')
            ->assertRedirect('/console/dashboard');
    }

    public function test_notice_page_redirects_unauthenticated_user_to_login(): void
    {
        $this->get('/console/verify-email')
            ->assertRedirect('/console/login');
    }

    // ── verify ───────────────────────────────────────────────────────────────

    public function test_valid_signed_link_verifies_email_and_redirects_to_dashboard(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        $this->actingAs($user)
            ->get($url)
            ->assertRedirect('/console/dashboard');

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_invalid_signed_link_returns_403(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get('/console/email/verify/' . $user->id . '/badhash')
            ->assertStatus(403);
    }

    // ── send ─────────────────────────────────────────────────────────────────

    public function test_resend_sends_verification_notification(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->post('/console/email/verification-notification')
            ->assertRedirect()
            ->assertSessionHas('status', 'verification-link-sent');

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_resend_does_not_send_for_already_verified_user(): void
    {
        Notification::fake();

        $user = User::factory()->create(); // verified

        $this->actingAs($user)
            ->post('/console/email/verification-notification')
            ->assertRedirect('/console/dashboard');

        Notification::assertNothingSent();
    }

    // ── middleware: unverified user blocked from dashboard ───────────────────

    public function test_unverified_user_accessing_dashboard_is_redirected_to_notice(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get('/console/dashboard')
            ->assertRedirect('/console/verify-email');
    }
}
