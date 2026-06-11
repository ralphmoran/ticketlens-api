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

    public function test_owner_login_redirects_to_owner_dashboard(): void
    {
        $owner = User::factory()->create([
            'email'      => 'owner@example.com',
            'password'   => bcrypt('password'),
            'tier'       => 'owner',
            'is_owner'   => true,
            'permissions' => 0,
        ]);

        $this->post('/console/login', [
            'email'    => 'owner@example.com',
            'password' => 'password',
        ])->assertRedirect('/console/owner/dashboard');
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

    public function test_lock_stats_object_has_required_keys(): void
    {
        $user = User::factory()->create(['tier' => 'pro', 'permissions' => 71]);

        $this->actingAs($user)->get('/console/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('stats', fn ($s) => collect($s)->has(['pushes_this_month', 'current_ticket_count', 'push_streak', 'last_push']))
            );
    }

    public function test_lock_free_tier_never_gets_hour_or_dow_charts(): void
    {
        $user = User::factory()->create(['tier' => 'free', 'permissions' => 64]);

        TriageSnapshot::create([
            'user_id'      => $user->id,
            'profile'      => 'production',
            'tickets'      => [],
            'ticket_count' => 3,
            'captured_at'  => now()->subDays(2),
        ]);

        $this->actingAs($user)->get('/console/dashboard')
            ->assertInertia(fn ($page) => $page
                ->missing('hour_distribution')
                ->missing('day_of_week_dist')
            );
    }

    public function test_lock_free_tier_ticket_trend_is_empty(): void
    {
        $user = User::factory()->create(['tier' => 'free', 'permissions' => 64]);

        TriageSnapshot::create([
            'user_id'      => $user->id,
            'profile'      => 'production',
            'tickets'      => [],
            'ticket_count' => 5,
            'captured_at'  => now()->subDays(1),
        ]);

        $this->actingAs($user)->get('/console/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('ticket_trend', [])
            );
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

    public function test_dashboard_passes_daily_urgency_for_pro_plus(): void
    {
        $user = User::factory()->create(['tier' => 'pro', 'permissions' => 71]);

        TriageSnapshot::create([
            'user_id'      => $user->id,
            'profile'      => 'production',
            'tickets'      => [
                ['key' => 'PROJ-1', 'flags' => ['needs-response']],
                ['key' => 'PROJ-2', 'flags' => ['aging']],
                ['key' => 'PROJ-3', 'flags' => []],
            ],
            'ticket_count' => 3,
            'captured_at'  => now()->subDays(1),
        ]);

        $this->actingAs($user)->get('/console/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('daily_urgency')
                ->where('daily_urgency', function ($v) {
                    if (count($v) < 1) return false;
                    $day = $v[0];
                    return $day['needs_response'] === 1
                        && $day['aging'] === 1
                        && $day['clear'] === 1;
                })
            );
    }

    public function test_dashboard_daily_urgency_empty_for_free_tier(): void
    {
        $user = User::factory()->create(['tier' => 'free', 'permissions' => 64]);

        TriageSnapshot::create([
            'user_id'      => $user->id,
            'profile'      => 'production',
            'tickets'      => [['key' => 'PROJ-1', 'flags' => ['needs-response']]],
            'ticket_count' => 1,
            'captured_at'  => now()->subDays(1),
        ]);

        $this->actingAs($user)->get('/console/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('daily_urgency', [])
            );
    }

    public function test_dashboard_daily_urgency_scoped_to_current_user(): void
    {
        $user  = User::factory()->create(['tier' => 'pro', 'permissions' => 71]);
        $other = User::factory()->create(['tier' => 'pro', 'permissions' => 71]);

        // Other user's snapshot — should not appear in user's daily_urgency
        TriageSnapshot::create([
            'user_id'      => $other->id,
            'profile'      => 'production',
            'tickets'      => [['key' => 'PROJ-99', 'flags' => ['needs-response']]],
            'ticket_count' => 1,
            'captured_at'  => now()->subDays(1),
        ]);

        $this->actingAs($user)->get('/console/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('daily_urgency', [])
            );
    }

    // ── RED: per-tier analytics new fields ────────────────────────────────────

    public function test_pro_tier_gets_hour_distribution_with_24_buckets(): void
    {
        $user = User::factory()->create(['tier' => 'pro', 'permissions' => 71]);

        TriageSnapshot::create([
            'user_id'      => $user->id,
            'profile'      => 'production',
            'tickets'      => [],
            'ticket_count' => 2,
            'captured_at'  => now()->subDays(1)->setHour(14),
        ]);

        $this->actingAs($user)->get('/console/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('hour_distribution')
                ->where('hour_distribution', fn ($v) => count($v) === 24)
            );
    }

    public function test_pro_tier_gets_day_of_week_dist_with_7_buckets(): void
    {
        $user = User::factory()->create(['tier' => 'pro', 'permissions' => 71]);

        TriageSnapshot::create([
            'user_id'      => $user->id,
            'profile'      => 'production',
            'tickets'      => [],
            'ticket_count' => 2,
            'captured_at'  => now()->subDays(1),
        ]);

        $this->actingAs($user)->get('/console/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('day_of_week_dist')
                ->where('day_of_week_dist', fn ($v) => count($v) === 7)
            );
    }

    public function test_period_param_7_filters_ticket_trend_to_7_days(): void
    {
        $user = User::factory()->create(['tier' => 'pro', 'permissions' => 71]);

        // Snapshot at 10 days ago — outside 7-day window, inside 30-day default
        TriageSnapshot::create([
            'user_id'      => $user->id,
            'profile'      => 'production',
            'tickets'      => [],
            'ticket_count' => 3,
            'captured_at'  => now()->subDays(10),
        ]);

        // Snapshot within 7 days
        TriageSnapshot::create([
            'user_id'      => $user->id,
            'profile'      => 'production',
            'tickets'      => [],
            'ticket_count' => 5,
            'captured_at'  => now()->subDays(3),
        ]);

        $this->actingAs($user)->get('/console/dashboard?period=7')
            ->assertInertia(fn ($page) => $page
                ->has('ticket_trend')
                ->where('ticket_trend', fn ($v) =>
                    count($v) === 1 && $v[0]['count'] === 5
                )
            );
    }

    public function test_period_param_invalid_falls_back_to_30_days(): void
    {
        $user = User::factory()->create(['tier' => 'pro', 'permissions' => 71]);

        TriageSnapshot::create([
            'user_id'      => $user->id,
            'profile'      => 'production',
            'tickets'      => [],
            'ticket_count' => 4,
            'captured_at'  => now()->subDays(15),
        ]);

        $this->actingAs($user)->get('/console/dashboard?period=999')
            ->assertInertia(fn ($page) => $page
                ->has('ticket_trend')
                ->where('ticket_trend', fn ($v) => count($v) === 1)
            );
    }
}
