<?php

namespace Tests\Feature\Owner;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InsightsControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeOwner(): User
    {
        return User::factory()->create(['is_owner' => true]);
    }

    private function insertCliLog(int $userId, string $action, int $tokensSaved, int $count = 1, int $daysAgo = 0): void
    {
        DB::table('usage_logs')->insert([
            'user_id'       => $userId,
            'action'        => $action,
            'ticket_key'    => null,
            'tokens_used'   => $tokensSaved,
            'command_count' => $count,
            'metadata'      => json_encode(['count' => $count, 'flags' => []]),
            'created_at'    => now()->subDays($daysAgo)->toDateTimeString(),
        ]);
    }

    // ── LOCK ───────────────────────────────────────────────────────────────

    public function test_lock_non_owner_redirected_from_insights(): void
    {
        $user = User::factory()->create(['tier' => 'pro']);
        $this->actingAs($user)->get('/console/owner/insights')->assertRedirect('/console/dashboard');
    }

    public function test_lock_guest_redirected_from_insights(): void
    {
        $this->get('/console/owner/insights')->assertRedirect('/console/login');
    }

    // ── RED → GREEN ────────────────────────────────────────────────────────

    public function test_owner_can_view_insights_page(): void
    {
        $owner = $this->makeOwner();
        $this->actingAs($owner)->get('/console/owner/insights')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Console/Owner/Insights'));
    }

    public function test_insights_returns_popular_commands(): void
    {
        $owner = $this->makeOwner();
        $user  = User::factory()->create(['tier' => 'pro']);

        $this->insertCliLog($user->id, 'fetch', 100, 5);
        $this->insertCliLog($user->id, 'triage', 50, 2);

        $this->actingAs($owner)->get('/console/owner/insights')
            ->assertInertia(fn ($page) => $page
                ->has('popular_commands')
                ->where('popular_commands.0.action', 'fetch')
                ->where('popular_commands.0.total_runs', 5)
            );
    }

    public function test_insights_returns_tokens_saved_total(): void
    {
        $owner = $this->makeOwner();
        $user  = User::factory()->create(['tier' => 'pro']);

        $this->insertCliLog($user->id, 'fetch', 2000, 10);
        $this->insertCliLog($user->id, 'triage', 500, 5);

        $this->actingAs($owner)->get('/console/owner/insights')
            ->assertInertia(fn ($page) => $page
                ->has('tokens_saved_total')
                ->where('tokens_saved_total', 2500)
            );
    }

    public function test_insights_returns_roi_per_account(): void
    {
        $owner = $this->makeOwner();
        $pro   = User::factory()->create(['tier' => 'pro']);

        $this->insertCliLog($pro->id, 'fetch', 1_000_000, 1);

        $this->actingAs($owner)->get('/console/owner/insights')
            ->assertInertia(fn ($page) => $page
                ->has('roi_per_account')
                ->where('roi_per_account.data.0.user_id', $pro->id)
                ->where('roi_per_account.data.0.tokens_saved', 1_000_000)
            );
    }

    public function test_insights_free_user_roi_is_null(): void
    {
        $owner = $this->makeOwner();
        $free  = User::factory()->create(['tier' => 'free']);

        $this->insertCliLog($free->id, 'fetch', 999_999, 1);

        $this->actingAs($owner)->get('/console/owner/insights')
            ->assertInertia(fn ($page) => $page
                ->has('roi_per_account')
                ->where('roi_per_account.data.0.roi', null)
            );
    }

    public function test_insights_returns_feature_adoption(): void
    {
        $owner = $this->makeOwner();
        $u1    = User::factory()->create(['tier' => 'pro']);
        $u2    = User::factory()->create(['tier' => 'pro']);

        $this->insertCliLog($u1->id, 'fetch', 100, 1);
        $this->insertCliLog($u2->id, 'triage', 50, 1);
        $this->insertCliLog($u1->id, 'triage', 50, 1);

        $this->actingAs($owner)->get('/console/owner/insights')
            ->assertInertia(fn ($page) => $page
                ->has('feature_adoption')
                ->where('feature_adoption.triage', 2)
            );
    }

    public function test_insights_returns_top_accounts(): void
    {
        $owner = $this->makeOwner();
        $u1    = User::factory()->create(['tier' => 'pro', 'email' => 'top@example.com']);
        $u2    = User::factory()->create(['tier' => 'pro', 'email' => 'low@example.com']);

        $this->insertCliLog($u1->id, 'fetch', 5000, 50);
        $this->insertCliLog($u2->id, 'fetch', 100,  5);

        $this->actingAs($owner)->get('/console/owner/insights')
            ->assertInertia(fn ($page) => $page
                ->has('top_accounts')
                ->where('top_accounts.data.0.email', 'top@example.com')
                ->where('top_accounts.data.0.commands_run', 50)
            );
    }

    public function test_insights_excludes_byok_rows_from_tokens_saved(): void
    {
        $owner = $this->makeOwner();
        $user  = User::factory()->create(['tier' => 'pro']);

        // CLI row (metadata not null) — should be included
        $this->insertCliLog($user->id, 'fetch', 1000, 5);

        // BYOK row (metadata null) — must be excluded from tokens_saved_total
        DB::table('usage_logs')->insert([
            'user_id'    => $user->id,
            'action'     => 'digest',
            'ticket_key' => null,
            'tokens_used'=> 99999,
            'metadata'   => null,
            'created_at' => now()->toDateTimeString(),
        ]);

        $this->actingAs($owner)->get('/console/owner/insights')
            ->assertInertia(fn ($page) => $page
                ->where('tokens_saved_total', 1000)
            );
    }

    public function test_insights_period_param_all_falls_back_to_30d(): void
    {
        $owner = $this->makeOwner();
        $user  = User::factory()->create(['tier' => 'pro']);

        // 200-day-old log is outside the 30d fallback window — must not be included
        $this->insertCliLog($user->id, 'fetch', 999, 1, 200);

        $this->actingAs($owner)->get('/console/owner/insights?period=all')
            ->assertInertia(fn ($page) => $page
                ->where('tokens_saved_total', 0)
            );
    }

    // ── New: name field in top_accounts + roi_per_account ─────────────────

    public function test_top_accounts_include_name(): void
    {
        $owner = $this->makeOwner();
        $user  = User::factory()->create(['tier' => 'pro', 'name' => 'Alice Tester']);

        $this->insertCliLog($user->id, 'fetch', 1000, 10);

        $this->actingAs($owner)->get('/console/owner/insights')
            ->assertInertia(fn ($page) => $page
                ->has('top_accounts')
                ->where('top_accounts.data.0.name', 'Alice Tester')
            );
    }

    public function test_roi_per_account_includes_name(): void
    {
        $owner = $this->makeOwner();
        $user  = User::factory()->create(['tier' => 'pro', 'name' => 'Bob Sender']);

        $this->insertCliLog($user->id, 'triage', 500_000, 5);

        $this->actingAs($owner)->get('/console/owner/insights')
            ->assertInertia(fn ($page) => $page
                ->has('roi_per_account')
                ->where('roi_per_account.data.0.name', 'Bob Sender')
            );
    }

    // ── New: prev-period values ────────────────────────────────────────────

    public function test_insights_returns_prev_period_tokens_saved(): void
    {
        $owner = $this->makeOwner();
        $user  = User::factory()->create(['tier' => 'pro']);

        // Current period (last 7 days)
        $this->insertCliLog($user->id, 'fetch', 3000, 3, 2);
        // Previous period (7-14 days ago)
        $this->insertCliLog($user->id, 'fetch', 1000, 1, 10);

        $this->actingAs($owner)->get('/console/owner/insights?period=7')
            ->assertInertia(fn ($page) => $page
                ->has('prev_period_tokens_saved')
                ->where('prev_period_tokens_saved', 1000)
            );
    }

    public function test_insights_returns_prev_period_active_users(): void
    {
        $owner = $this->makeOwner();
        $u1    = User::factory()->create(['tier' => 'pro']);
        $u2    = User::factory()->create(['tier' => 'pro']);

        // Current period
        $this->insertCliLog($u1->id, 'fetch', 100, 1, 2);
        // Previous period (7-14 days ago)
        $this->insertCliLog($u2->id, 'fetch', 100, 1, 10);

        $this->actingAs($owner)->get('/console/owner/insights?period=7')
            ->assertInertia(fn ($page) => $page
                ->has('prev_period_active_users')
                ->where('prev_period_active_users', 1)
            );
    }

    public function test_prev_period_computed_even_when_period_is_all(): void
    {
        $owner = $this->makeOwner();

        // period=all falls back to 30d; prev period is always computed now (no null)
        $this->actingAs($owner)->get('/console/owner/insights?period=all')
            ->assertInertia(fn ($page) => $page
                ->where('prev_period_tokens_saved', 0)
                ->where('prev_period_active_users', 0)
            );
    }

    // ── New: daily series for sparklines ─────────────────────────────────────

    public function test_insights_returns_tokens_saved_by_day_for_7d(): void
    {
        $owner = $this->makeOwner();
        $user  = User::factory()->create(['tier' => 'pro']);

        $this->insertCliLog($user->id, 'fetch', 500, 5, 2);

        $this->actingAs($owner)->get('/console/owner/insights?period=7')
            ->assertInertia(fn ($page) => $page
                ->has('tokens_saved_by_day')
                ->has('tokens_saved_by_day', 7)
                ->where('tokens_saved_by_day.0.date', now()->subDays(6)->format('Y-m-d'))
                ->has('tokens_saved_by_day.0.value')
            );
    }

    public function test_insights_returns_active_users_by_day_for_7d(): void
    {
        $owner = $this->makeOwner();
        $u1    = User::factory()->create(['tier' => 'pro']);
        $u2    = User::factory()->create(['tier' => 'pro']);

        $this->insertCliLog($u1->id, 'fetch', 100, 1, 1);
        $this->insertCliLog($u2->id, 'fetch', 100, 1, 1);

        $this->actingAs($owner)->get('/console/owner/insights?period=7')
            ->assertInertia(fn ($page) => $page
                ->has('active_users_by_day')
                ->has('active_users_by_day', 7)
                ->where('active_users_by_day.0.date', now()->subDays(6)->format('Y-m-d'))
                ->has('active_users_by_day.0.value')
            );
    }

    public function test_insights_period_90_returns_90_days_of_data(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)->get('/console/owner/insights?period=90')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('period', '90')
                ->has('tokens_saved_by_day', 90)
            );
    }

    public function test_insights_period_14_returns_14_days_of_data(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)->get('/console/owner/insights?period=14')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('period', '14')
                ->has('tokens_saved_by_day', 14)
            );
    }

    public function test_insights_period_60_returns_60_days_of_data(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)->get('/console/owner/insights?period=60')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('period', '60')
                ->has('tokens_saved_by_day', 60)
            );
    }

    // ── New: command_count backfill (CRITICAL perf fix — audit 2026-07-07 §2.1) ──

    public function test_migration_backfills_command_count_from_existing_metadata(): void
    {
        $user = User::factory()->create(['tier' => 'pro']);

        DB::table('usage_logs')->insert([
            'user_id'       => $user->id,
            'action'        => 'fetch',
            'ticket_key'    => null,
            'tokens_used'   => 100,
            'metadata'      => json_encode(['count' => 7, 'flags' => []]),
            'command_count' => 0, // simulates a pre-migration row out of sync
            'created_at'    => now(),
        ]);

        $migration = require database_path('migrations/2026_07_08_000001_add_command_count_to_usage_logs.php');
        $migration->backfillCommandCounts();

        $this->assertSame(7, DB::table('usage_logs')->where('user_id', $user->id)->value('command_count'));
    }

    public function test_backfill_command_counts_is_idempotent(): void
    {
        $user = User::factory()->create(['tier' => 'pro']);

        DB::table('usage_logs')->insert([
            'user_id'       => $user->id,
            'action'        => 'fetch',
            'ticket_key'    => null,
            'tokens_used'   => 100,
            'metadata'      => json_encode(['count' => 3, 'flags' => []]),
            'command_count' => 3,
            'created_at'    => now(),
        ]);

        $migration = require database_path('migrations/2026_07_08_000001_add_command_count_to_usage_logs.php');
        $migration->backfillCommandCounts();
        $migration->backfillCommandCounts();

        $this->assertSame(3, DB::table('usage_logs')->where('user_id', $user->id)->value('command_count'));
    }

    public function test_backfilled_command_count_is_reflected_in_insights_response(): void
    {
        $owner = $this->makeOwner();
        $user  = User::factory()->create(['tier' => 'pro']);

        // Simulates a pre-migration row: metadata has the real count, but
        // command_count is still at its column default (out of sync) until
        // the backfill runs.
        DB::table('usage_logs')->insert([
            'user_id'       => $user->id,
            'action'        => 'fetch',
            'ticket_key'    => null,
            'tokens_used'   => 500,
            'metadata'      => json_encode(['count' => 11, 'flags' => []]),
            'command_count' => 0,
            'created_at'    => now(),
        ]);

        $migration = require database_path('migrations/2026_07_08_000001_add_command_count_to_usage_logs.php');
        $migration->backfillCommandCounts();

        $this->actingAs($owner)->get('/console/owner/insights')
            ->assertInertia(fn ($page) => $page
                ->where('popular_commands.0.action', 'fetch')
                ->where('popular_commands.0.total_runs', 11)
                ->where('top_accounts.data.0.commands_run', 11)
            );
    }

    // ── New: bounded-query proof (CRITICAL perf fix — audit 2026-07-07 §2.1) ──

    public function test_insights_query_count_stays_bounded_regardless_of_row_volume(): void
    {
        $owner = $this->makeOwner();
        $user  = User::factory()->create(['tier' => 'pro']);

        for ($i = 0; $i < 5; $i++) {
            $this->insertCliLog($user->id, 'fetch', 100, 1);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($owner)->get('/console/owner/insights?period=30')->assertOk();
        $smallVolumeQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        for ($i = 0; $i < 200; $i++) {
            $this->insertCliLog($user->id, 'fetch', 10, 1);
        }

        // Bust the response cache so this measurement exercises real query
        // behavior again, not a cache hit from the first call above — this
        // test proves SQL query count is bounded, independent of caching.
        Cache::flush();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($owner)->get('/console/owner/insights?period=30')->assertOk();
        $largeVolumeQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            $smallVolumeQueryCount,
            $largeVolumeQueryCount,
            'Insights query count must not scale with usage_logs row volume.'
        );
    }

    public function test_insights_queries_never_select_raw_usage_log_rows(): void
    {
        $owner = $this->makeOwner();
        $user  = User::factory()->create(['tier' => 'pro']);
        $this->insertCliLog($user->id, 'fetch', 100, 5);

        DB::enableQueryLog();
        $this->actingAs($owner)->get('/console/owner/insights?period=30')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $usageLogQueries = array_filter($queries, fn ($q) => str_contains($q['query'], 'usage_logs'));
        $this->assertNotEmpty($usageLogQueries, 'Expected at least one usage_logs query.');

        foreach ($usageLogQueries as $q) {
            $sql = strtolower($q['query']);
            $isAggregate = str_contains($sql, 'sum(') || str_contains($sql, 'count(') || str_contains($sql, 'group by');
            $this->assertTrue($isAggregate, "Non-aggregate usage_logs query found: {$q['query']}");
        }
    }

    // ── LOCK: pre-existing account-inclusion behaviour (§3.8 regression surface) ──

    public function test_owner_account_with_cli_usage_still_appears_in_top_accounts(): void
    {
        $owner = $this->makeOwner();

        $this->insertCliLog($owner->id, 'fetch', 1000, 10);

        $this->actingAs($owner)->get('/console/owner/insights')
            ->assertInertia(fn ($page) => $page
                ->has('top_accounts')
                ->where('top_accounts.data.0.user_id', $owner->id)
            );
    }

    public function test_soft_deleted_account_with_cli_usage_still_appears_in_top_accounts(): void
    {
        $owner = $this->makeOwner();
        $user  = User::factory()->create(['tier' => 'pro']);

        $this->insertCliLog($user->id, 'fetch', 1000, 10);
        $user->delete(); // SoftDeletes — must not vanish from owner-facing revenue/ROI totals

        $this->actingAs($owner)->get('/console/owner/insights')
            ->assertInertia(fn ($page) => $page
                ->where('top_accounts.total', 1)
                ->where('top_accounts.data.0.user_id', $user->id)
            );
    }

    // ── Caching ────────────────────────────────────────────────────────────

    public function test_insights_response_is_served_from_cache_on_second_request_within_ttl(): void
    {
        $owner = $this->makeOwner();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($owner)->get('/console/owner/insights?period=30')->assertOk();
        $coldRequestQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($owner)->get('/console/owner/insights?period=30')->assertOk();
        $cachedRequestQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(
            $coldRequestQueries,
            $cachedRequestQueries,
            'Second insights request within TTL must skip the aggregate queries (cache hit).'
        );
    }

    public function test_insights_cache_is_scoped_per_period(): void
    {
        $owner = $this->makeOwner();
        $this->actingAs($owner)->get('/console/owner/insights?period=30')->assertOk();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($owner)->get('/console/owner/insights?period=60')->assertOk();
        $differentPeriodQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThan(
            0,
            $differentPeriodQueries,
            'A different period value must not be served from the period=30 cache entry.'
        );
    }

    // ── New: server-side pagination (audit §3.8) ────────────────────────────

    public function test_top_accounts_payload_size_bounded_by_per_page_regardless_of_total_users(): void
    {
        $owner = $this->makeOwner();

        for ($i = 0; $i < 15; $i++) {
            $user = User::factory()->create(['tier' => 'pro']);
            $this->insertCliLog($user->id, 'fetch', 100, 1);
        }

        $this->actingAs($owner)->get('/console/owner/insights')
            ->assertInertia(fn ($page) => $page
                ->has('top_accounts.data', 10)
                ->where('top_accounts.total', 15)
                ->where('top_accounts.current_page', 1)
            );
    }

    public function test_accounts_per_page_is_clamped_between_1_and_100(): void
    {
        $owner = $this->makeOwner();
        $user  = User::factory()->create(['tier' => 'pro']);
        $this->insertCliLog($user->id, 'fetch', 100, 1);

        $this->actingAs($owner)->get('/console/owner/insights?accounts_per_page=500')
            ->assertInertia(fn ($page) => $page->where('top_accounts.per_page', 100));

        $this->actingAs($owner)->get('/console/owner/insights?accounts_per_page=0')
            ->assertInertia(fn ($page) => $page->where('top_accounts.per_page', 1));
    }

    public function test_accounts_search_filters_by_name_or_email(): void
    {
        $owner = $this->makeOwner();
        $alice = User::factory()->create(['tier' => 'pro', 'name' => 'Alice Tester', 'email' => 'alice@example.com']);
        $bob   = User::factory()->create(['tier' => 'pro', 'name' => 'Bob Sender', 'email' => 'bob@example.com']);
        $this->insertCliLog($alice->id, 'fetch', 100, 1);
        $this->insertCliLog($bob->id, 'fetch', 100, 1);

        $this->actingAs($owner)->get('/console/owner/insights?accounts_search=Alice')
            ->assertInertia(fn ($page) => $page
                ->where('top_accounts.total', 1)
                ->where('top_accounts.data.0.user_id', $alice->id)
            );
    }

    public function test_roi_search_filters_by_name_or_email(): void
    {
        $owner = $this->makeOwner();
        $alice = User::factory()->create(['tier' => 'pro', 'name' => 'Alice Tester', 'email' => 'alice@example.com']);
        $bob   = User::factory()->create(['tier' => 'pro', 'name' => 'Bob Sender', 'email' => 'bob@example.com']);
        $this->insertCliLog($alice->id, 'fetch', 100, 1);
        $this->insertCliLog($bob->id, 'fetch', 100, 1);

        $this->actingAs($owner)->get('/console/owner/insights?roi_search=bob@example.com')
            ->assertInertia(fn ($page) => $page
                ->where('roi_per_account.total', 1)
                ->where('roi_per_account.data.0.user_id', $bob->id)
            );
    }

    public function test_tied_commands_run_have_stable_page_boundaries(): void
    {
        $owner = $this->makeOwner();
        $users = collect(range(1, 12))->map(function () {
            $user = User::factory()->create(['tier' => 'pro']);
            $this->insertCliLog($user->id, 'fetch', 100, 5); // identical commands_run for every account
            return $user->id;
        });

        $page1 = $this->actingAs($owner)->get('/console/owner/insights?accounts_per_page=5&accounts_page=1');
        $page2 = $this->actingAs($owner)->get('/console/owner/insights?accounts_per_page=5&accounts_page=2');

        $page1Ids = collect($page1->inertiaProps('top_accounts.data'))->pluck('user_id');
        $page2Ids = collect($page2->inertiaProps('top_accounts.data'))->pluck('user_id');

        $this->assertCount(5, $page1Ids);
        $this->assertCount(5, $page2Ids);
        $this->assertEmpty(
            $page1Ids->intersect($page2Ids),
            'Tied rows must not reappear across pages — ordering must be deterministic.'
        );
    }

    public function test_paging_top_accounts_does_not_reset_roi_per_account_page(): void
    {
        $owner = $this->makeOwner();
        for ($i = 0; $i < 12; $i++) {
            $user = User::factory()->create(['tier' => 'pro']);
            $this->insertCliLog($user->id, 'fetch', 100, 1);
        }

        $this->actingAs($owner)
            ->get('/console/owner/insights?accounts_page=2&roi_page=3&roi_per_page=5')
            ->assertInertia(fn ($page) => $page
                ->where('top_accounts.current_page', 2)
                ->where('roi_per_account.current_page', 3)
            );
    }

    public function test_kpi_cache_is_not_invalidated_by_account_table_pagination_changes(): void
    {
        $owner = $this->makeOwner();
        for ($i = 0; $i < 15; $i++) {
            $user = User::factory()->create(['tier' => 'pro']);
            $this->insertCliLog($user->id, 'fetch', 100, 1);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $page1 = $this->actingAs($owner)->get('/console/owner/insights?period=30&accounts_page=1')->assertOk();
        $coldRequestQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $page2 = $this->actingAs($owner)->get('/console/owner/insights?period=30&accounts_page=2')->assertOk();
        $pagedRequestQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(
            $coldRequestQueries,
            $pagedRequestQueries,
            'Changing account-table pagination must still hit the KPI/chart cache — only the two account queries should run fresh.'
        );

        $page1UserIds = collect($page1->inertiaProps('top_accounts.data'))->pluck('user_id');
        $page2UserIds = collect($page2->inertiaProps('top_accounts.data'))->pluck('user_id');
        $this->assertNotEquals(
            $page1UserIds,
            $page2UserIds,
            'accounts_page=2 must return different rows than accounts_page=1, proving the account query is not served stale from cache.'
        );
    }
}
