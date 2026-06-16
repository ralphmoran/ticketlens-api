<?php

namespace Tests\Feature\Owner;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'user_id'    => $userId,
            'action'     => $action,
            'ticket_key' => null,
            'tokens_used'=> $tokensSaved,
            'metadata'   => json_encode(['count' => $count, 'flags' => []]),
            'created_at' => now()->subDays($daysAgo)->toDateTimeString(),
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
                ->where('roi_per_account.0.user_id', $pro->id)
                ->where('roi_per_account.0.tokens_saved', 1_000_000)
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
                ->where('roi_per_account.0.roi', null)
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
                ->where('top_accounts.0.email', 'top@example.com')
                ->where('top_accounts.0.commands_run', 50)
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
                ->where('top_accounts.0.name', 'Alice Tester')
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
                ->where('roi_per_account.0.name', 'Bob Sender')
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
}
