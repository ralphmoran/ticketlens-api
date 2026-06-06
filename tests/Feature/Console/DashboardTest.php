<?php

namespace Tests\Feature\Console;

use App\Models\TriageSnapshot;
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

    // ── LOCK: dashboard renders without crashing for any tier ─────────────────

    public function test_lock_dashboard_renders_for_free_tier(): void
    {
        $user = User::factory()->create(['tier' => 'free', 'permissions' => 64]);

        $this->actingAs($user)->get('/console/dashboard')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page->component('Console/Dashboard'));
    }

    public function test_lock_dashboard_renders_for_team_tier(): void
    {
        $user = User::factory()->create(['tier' => 'team', 'permissions' => 511]);

        $this->actingAs($user)->get('/console/dashboard')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page->component('Console/Dashboard'));
    }

    // ── RED: dashboard stats props ────────────────────────────────────────────

    public function test_dashboard_passes_stats_props_to_vue(): void
    {
        $user = User::factory()->create(['tier' => 'team', 'permissions' => 511]);

        $this->actingAs($user)->get('/console/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('stats')
                ->where('stats.pushes_this_month', 0)
                ->where('stats.current_ticket_count', 0)
                ->where('stats.push_streak', 0)
                ->where('stats.last_push', null)
            );
    }

    public function test_dashboard_counts_pushes_this_month(): void
    {
        $user = User::factory()->create(['tier' => 'team', 'permissions' => 511]);

        TriageSnapshot::create([
            'user_id'      => $user->id,
            'profile'      => 'production',
            'tickets'      => [],
            'ticket_count' => 5,
            'captured_at'  => now()->startOfMonth()->addDays(2),
        ]);

        $this->actingAs($user)->get('/console/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('stats.pushes_this_month', 1)
                ->where('stats.current_ticket_count', 5)
            );
    }

    public function test_dashboard_computes_push_streak(): void
    {
        $user = User::factory()->create(['tier' => 'team', 'permissions' => 511]);

        foreach (range(0, 2) as $daysAgo) {
            TriageSnapshot::create([
                'user_id'      => $user->id,
                'profile'      => 'production',
                'tickets'      => [],
                'ticket_count' => 3,
                'captured_at'  => now()->subDays($daysAgo)->startOfDay()->addHours(10),
            ]);
        }

        $this->actingAs($user)->get('/console/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('stats.push_streak', 3)
            );
    }

    public function test_dashboard_passes_ticket_trend_for_pro_plus(): void
    {
        $user = User::factory()->create(['tier' => 'pro', 'permissions' => 71]);

        TriageSnapshot::create([
            'user_id'      => $user->id,
            'profile'      => 'production',
            'tickets'      => [],
            'ticket_count' => 4,
            'captured_at'  => now()->subDays(1),
        ]);

        $this->actingAs($user)->get('/console/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('ticket_trend')
                ->where('ticket_trend', fn ($v) => count($v) >= 1)
            );
    }
}
