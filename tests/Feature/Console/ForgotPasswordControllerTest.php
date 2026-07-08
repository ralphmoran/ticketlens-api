<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ForgotPasswordControllerTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC_STATUS = 'If that email address is registered, we sent a reset link.';

    public function test_get_route_renders_login_page_with_forgot_tab(): void
    {
        $this->get('/console/forgot-password')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Login')
                ->where('initialTab', 'forgot')
            );
    }

    public function test_get_route_redirects_authenticated_users(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/console/forgot-password')
            ->assertRedirect(route('console.dashboard'));
    }

    public function test_send_dispatches_reset_link_for_known_email(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'known@example.com']);

        $this->post('/console/forgot-password', ['email' => 'known@example.com'])
            ->assertRedirect(route('console.login'))
            ->assertSessionHas('status', self::GENERIC_STATUS);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_send_returns_same_response_for_unknown_email(): void
    {
        Notification::fake();

        $this->post('/console/forgot-password', ['email' => 'nobody@example.com'])
            ->assertRedirect(route('console.login'))
            ->assertSessionHas('status', self::GENERIC_STATUS);

        Notification::assertNothingSent();
    }

    public function test_send_requires_email_field(): void
    {
        $this->post('/console/forgot-password', [])
            ->assertSessionHasErrors('email');
    }

    public function test_send_requires_valid_email_format(): void
    {
        $this->post('/console/forgot-password', ['email' => 'notanemail'])
            ->assertSessionHasErrors('email');
    }

    public function test_forgot_password_rejects_crlf_bearing_email(): void
    {
        $this->post('/console/forgot-password', [
            'email' => "victim@example.com\r\nBcc: evil@example.com",
        ])->assertSessionHasErrors('email');
    }

    public function test_send_blocks_authenticated_users(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/console/forgot-password', ['email' => $user->email])
            ->assertRedirect(route('console.dashboard'));

        Notification::assertNothingSent();
    }

    public function test_send_is_rate_limited(): void
    {
        Notification::fake();

        // Use a unique email per request so the per-email cap (3/hr) doesn't fire first.
        // This isolates the IP cap (5/min) as the trigger for the 429.
        for ($i = 0; $i < 5; $i++) {
            User::factory()->create(['email' => "ratelimit{$i}@example.com"]);
            $this->post('/console/forgot-password', ['email' => "ratelimit{$i}@example.com"])
                ->assertRedirect();
        }

        $this->post('/console/forgot-password', ['email' => 'ratelimit0@example.com'])
            ->assertStatus(429);
    }

    public function test_send_per_email_rate_limit_blocks_distributed_ips(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'flood@example.com']);

        // First 3 requests from different IPs exhaust the per-email hourly cap
        for ($i = 1; $i <= 3; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.$i"])
                ->post('/console/forgot-password', ['email' => 'flood@example.com'])
                ->assertRedirect();
        }

        // 4th request from yet another IP hits the per-email cap (not the IP cap)
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
            ->post('/console/forgot-password', ['email' => 'flood@example.com'])
            ->assertStatus(429);
    }
}
