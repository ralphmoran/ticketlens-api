<?php

namespace Tests\Feature\Owner;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeOwner(): User
    {
        return User::factory()->create(['is_owner' => true]);
    }

    // ── LOCK ───────────────────────────────────────────────────────────────

    public function test_lock_guest_redirected_from_owner_dashboard(): void
    {
        $this->get('/console/owner/dashboard')->assertRedirect('/console/login');
    }

    public function test_lock_non_owner_redirected_from_owner_dashboard(): void
    {
        $user = User::factory()->create(['tier' => 'team']);
        $this->actingAs($user)->get('/console/owner/dashboard')->assertRedirect('/console/dashboard');
    }

    public function test_lock_owner_can_view_dashboard(): void
    {
        $owner = $this->makeOwner();
        $this->actingAs($owner)->get('/console/owner/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Console/Owner/Dashboard'));
    }

    public function test_lock_dashboard_returns_total_users(): void
    {
        $owner = $this->makeOwner();
        User::factory()->create(['tier' => 'pro']);

        $this->actingAs($owner)->get('/console/owner/dashboard')
            ->assertInertia(fn ($page) => $page->has('stats.total_users'));
    }

    // ── RED → GREEN ────────────────────────────────────────────────────────

    public function test_dashboard_returns_active_users(): void
    {
        $owner = $this->makeOwner();
        $user  = User::factory()->create(['tier' => 'pro']);

        DB::table('usage_logs')->insert([
            'user_id'     => $user->id,
            'action'      => 'fetch',
            'ticket_key'  => null,
            'tokens_used' => 100,
            'metadata'    => json_encode(['count' => 1]),
            'created_at'  => now()->subDays(10)->toDateTimeString(),
        ]);

        $this->actingAs($owner)->get('/console/owner/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('stats.active_users')
                ->where('stats.active_users', 1)
            );
    }

    public function test_dashboard_active_users_excludes_pushes_older_than_30_days(): void
    {
        $owner = $this->makeOwner();
        $user  = User::factory()->create(['tier' => 'pro']);

        DB::table('usage_logs')->insert([
            'user_id'     => $user->id,
            'action'      => 'fetch',
            'ticket_key'  => null,
            'tokens_used' => 100,
            'metadata'    => json_encode(['count' => 1]),
            'created_at'  => now()->subDays(31)->toDateTimeString(),
        ]);

        $this->actingAs($owner)->get('/console/owner/dashboard')
            ->assertInertia(fn ($page) => $page->where('stats.active_users', 0));
    }

    public function test_dashboard_returns_user_status_chart(): void
    {
        $owner = $this->makeOwner();
        $user  = User::factory()->create(['tier' => 'pro']);

        DB::table('usage_logs')->insert([
            'user_id'     => $user->id,
            'action'      => 'fetch',
            'ticket_key'  => null,
            'tokens_used' => 100,
            'metadata'    => json_encode(['count' => 1]),
            'created_at'  => now()->toDateTimeString(),
        ]);

        $this->actingAs($owner)->get('/console/owner/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('stats.user_status_chart')
                ->has('stats.user_status_chart.labels')
                ->has('stats.user_status_chart.data')
            );
    }

    public function test_dashboard_returns_account_status_chart(): void
    {
        $owner    = $this->makeOwner();
        $active   = User::factory()->create(['tier' => 'pro']);
        $suspended = User::factory()->create(['tier' => 'pro', 'suspended_at' => now()]);

        $this->actingAs($owner)->get('/console/owner/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('stats.account_status_chart')
                ->has('stats.account_status_chart.labels')
                ->has('stats.account_status_chart.data')
            );
    }

    public function test_dashboard_active_users_is_zero_when_no_logs(): void
    {
        $owner = $this->makeOwner();
        $this->actingAs($owner)->get('/console/owner/dashboard')
            ->assertInertia(fn ($page) => $page->where('stats.active_users', 0));
    }

    public function test_dashboard_recent_actions_capped_at_100(): void
    {
        $owner  = $this->makeOwner();
        $actor  = User::factory()->create(['tier' => 'pro']);

        // Insert 110 audit log entries
        for ($i = 0; $i < 110; $i++) {
            DB::table('audit_logs')->insert([
                'actor_id'       => $actor->id,
                'target_user_id' => null,
                'action'         => 'test.action',
                'metadata'       => null,
                'created_at'     => now()->subSeconds($i)->toDateTimeString(),
            ]);
        }

        $this->actingAs($owner)->get('/console/owner/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('stats.recent_actions')
                ->where('stats.recent_actions', fn ($v) => count($v) <= 100)
            );
    }

    // ── Caching ────────────────────────────────────────────────────────────

    public function test_dashboard_stats_are_served_from_cache_on_second_request_within_ttl(): void
    {
        $owner = $this->makeOwner();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($owner)->get('/console/owner/dashboard')->assertOk();
        $coldRequestQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($owner)->get('/console/owner/dashboard')->assertOk();
        $cachedRequestQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(
            $coldRequestQueries,
            $cachedRequestQueries,
            'Second dashboard request within TTL must skip the stats aggregate queries (cache hit).'
        );
    }

    public function test_dashboard_recent_actions_survive_a_real_cache_round_trip(): void
    {
        // array (the test default) never serializes — it keeps a live PHP
        // reference, so it can't catch a value that fails PHP serialize()/
        // unserialize(). database is what production actually runs, and is
        // where a raw Eloquent Collection comes back as
        // __PHP_Incomplete_Class instead of usable data.
        config(['cache.default' => 'database']);

        $owner = $this->makeOwner();
        $actor = User::factory()->create(['tier' => 'pro']);
        DB::table('audit_logs')->insert([
            'actor_id'       => $actor->id,
            'target_user_id' => null,
            'action'         => 'client.suspended',
            'metadata'       => null,
            'created_at'     => now()->toDateTimeString(),
        ]);

        $this->actingAs($owner)->get('/console/owner/dashboard')->assertOk();

        $this->actingAs($owner)->get('/console/owner/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('stats.recent_actions.0.action', 'client.suspended')
            );
    }
}
