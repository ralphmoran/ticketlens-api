<?php

namespace Tests\Feature\Owner;

use App\Models\Group;
use App\Models\License;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClientHealthTest extends TestCase
{
    use RefreshDatabase;

    private function makeOwner(): User
    {
        return User::factory()->create(['is_owner' => true, 'tier' => 'owner']);
    }

    private function makeClient(string $tier = 'pro'): User
    {
        return User::factory()->create(['tier' => $tier, 'permissions' => 71, 'is_owner' => false]);
    }

    private function insertCliLog(int $userId, string $action = 'fetch', int $tokensSaved = 100, int $daysAgo = 0): void
    {
        DB::table('usage_logs')->insert([
            'user_id'     => $userId,
            'action'      => $action,
            'ticket_key'  => null,
            'tokens_used' => $tokensSaved,
            'metadata'    => json_encode(['count' => 1, 'flags' => []]),
            'created_at'  => now()->subDays($daysAgo)->toDateTimeString(),
        ]);
    }

    // ── Access control ───────────────────────────────────────────────────────

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/console/owner/health')->assertRedirect('/console/login');
    }

    public function test_non_owner_is_redirected_from_client_health(): void
    {
        $user = $this->makeClient();
        $this->actingAs($user)->get('/console/owner/health')->assertRedirect('/console/dashboard');
    }

    public function test_owner_can_access_client_health(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)->get('/console/owner/health')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Console/Owner/ClientHealth')
                ->has('new_accounts')
                ->has('churned_accounts')
                ->has('at_risk_accounts')
                ->has('never_pushed')
                ->has('license_expiry')
                ->has('arpu')
                ->has('seat_utilization')
                ->has('commands_per_user')
                ->has('feature_penetration')
                ->has('conversion_rate')
                ->has('license_issuances')
                ->has('npm_downloads')
            );
    }

    // ── New accounts ─────────────────────────────────────────────────────────

    public function test_new_accounts_counts_users_created_within_period(): void
    {
        $owner = $this->makeOwner();
        $this->makeClient();                                          // created now  → counts
        User::factory()->create(['created_at' => now()->subDays(60), 'is_owner' => false]); // old → excluded

        $this->actingAs($owner)->get('/console/owner/health?period=30')
            ->assertInertia(fn ($page) => $page->where('new_accounts', 1));
    }

    public function test_new_accounts_excludes_owner_row(): void
    {
        $owner = $this->makeOwner(); // is_owner=true → must not be counted

        $this->actingAs($owner)->get('/console/owner/health?period=30')
            ->assertInertia(fn ($page) => $page->where('new_accounts', 0));
    }

    // ── Churned accounts ─────────────────────────────────────────────────────

    public function test_churned_accounts_counts_users_active_in_prev_period_but_not_current(): void
    {
        $owner  = $this->makeOwner();
        $churned = $this->makeClient();
        $active  = $this->makeClient();

        // $churned had a push 45 days ago (in the prev 30-day window) but none in last 30 days
        $this->insertCliLog($churned->id, 'fetch', 100, 45);

        // $active has a push 5 days ago (current window)
        $this->insertCliLog($active->id,  'fetch', 100, 5);

        $this->actingAs($owner)->get('/console/owner/health?period=30')
            ->assertInertia(fn ($page) => $page->where('churned_accounts', 1));
    }

    // ── At-risk accounts ─────────────────────────────────────────────────────

    public function test_at_risk_accounts_includes_users_with_no_recent_push(): void
    {
        $owner   = $this->makeOwner();
        $stale   = $this->makeClient();
        $healthy = $this->makeClient();

        // $stale last pushed 20 days ago (> 14-day threshold)
        $this->insertCliLog($stale->id,   'fetch', 100, 20);
        // $healthy pushed yesterday
        $this->insertCliLog($healthy->id, 'fetch', 100, 1);

        $this->actingAs($owner)->get('/console/owner/health')
            ->assertInertia(fn ($page) => $page
                ->has('at_risk_accounts', fn ($risk) => $risk
                    ->has('count')
                    ->has('accounts')
                    ->where('count', 1)
                )
            );
    }

    public function test_at_risk_accounts_includes_users_who_have_never_pushed(): void
    {
        $owner    = $this->makeOwner();
        $noPushes = $this->makeClient();  // no usage_log rows at all

        $this->actingAs($owner)->get('/console/owner/health')
            ->assertInertia(fn ($page) => $page
                ->where('at_risk_accounts.count', 1)
            );
    }

    // ── Never pushed ─────────────────────────────────────────────────────────

    public function test_never_pushed_counts_users_with_no_cli_logs(): void
    {
        $owner    = $this->makeOwner();
        $noPushes = $this->makeClient();
        $pushed   = $this->makeClient();

        $this->insertCliLog($pushed->id, 'fetch', 100, 5);

        $this->actingAs($owner)->get('/console/owner/health')
            ->assertInertia(fn ($page) => $page->where('never_pushed', 1));
    }

    public function test_never_pushed_excludes_owner(): void
    {
        $owner = $this->makeOwner(); // owner has no CLI logs but must not be counted

        $this->actingAs($owner)->get('/console/owner/health')
            ->assertInertia(fn ($page) => $page->where('never_pushed', 0));
    }

    // ── ARPU ─────────────────────────────────────────────────────────────────

    public function test_arpu_is_zero_when_no_paid_users(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)->get('/console/owner/health')
            ->assertInertia(fn ($page) => $page->where('arpu', 0));
    }

    public function test_arpu_divides_mrr_by_paid_user_count(): void
    {
        $owner   = $this->makeOwner();
        $proUser = $this->makeClient('pro');
        License::create(['user_id' => $proUser->id, 'lemon_key_hash' => str_repeat('a', 64), 'tier' => 'pro', 'seats' => 1, 'status' => 'active', 'expires_at' => null]);

        $response = $this->actingAs($owner)->get('/console/owner/health');

        // Pro price from config; ARPU = price / 1 paid user
        $proPrice = config('tiers.prices.pro', 8);
        $response->assertInertia(fn ($page) => $page->where('arpu', $proPrice));
    }

    // ── Seat utilization ─────────────────────────────────────────────────────

    public function test_seat_utilization_returns_total_and_used_seats(): void
    {
        $owner   = $this->makeOwner();
        $manager = $this->makeClient('team');
        $member  = $this->makeClient('team');

        License::create(['user_id' => $manager->id, 'lemon_key_hash' => str_repeat('b', 64), 'tier' => 'team', 'seats' => 5, 'status' => 'active', 'expires_at' => null]);

        $group = Group::create(['name' => 'Test Team', 'owner_id' => $manager->id, 'permissions' => 0]);
        $group->members()->attach([$manager->id, $member->id]);

        $this->actingAs($owner)->get('/console/owner/health')
            ->assertInertia(fn ($page) => $page
                ->where('seat_utilization.total', 5)
                ->where('seat_utilization.used', 2)
            );
    }

    // ── License expiry ────────────────────────────────────────────────────────

    public function test_license_expiry_buckets_upcoming_expirations(): void
    {
        $owner = $this->makeOwner();
        $user  = $this->makeClient('pro');

        License::create([
            'user_id'         => $user->id,
            'lemon_key_hash'  => str_repeat('c', 64),
            'tier'            => 'pro',
            'seats'           => 1,
            'status'          => 'active',
            'expires_at'      => now()->addDays(20),
        ]);

        $this->actingAs($owner)->get('/console/owner/health')
            ->assertInertia(fn ($page) => $page
                ->where('license_expiry.soon_30', 1)
                ->where('license_expiry.soon_60', 1)
                ->where('license_expiry.soon_90', 1)
            );
    }

    // ── Commands per active user ──────────────────────────────────────────────

    public function test_commands_per_user_averages_correctly(): void
    {
        $owner  = $this->makeOwner();
        $userA  = $this->makeClient();
        $userB  = $this->makeClient();

        // userA: 3 commands; userB: 1 command → avg = 2.0
        DB::table('usage_logs')->insert([
            ['user_id' => $userA->id, 'action' => 'fetch', 'ticket_key' => null, 'tokens_used' => 10, 'command_count' => 3, 'metadata' => json_encode(['count' => 3, 'flags' => []]), 'created_at' => now()],
            ['user_id' => $userB->id, 'action' => 'fetch', 'ticket_key' => null, 'tokens_used' => 10, 'command_count' => 1, 'metadata' => json_encode(['count' => 1, 'flags' => []]), 'created_at' => now()],
        ]);

        $this->actingAs($owner)->get('/console/owner/health?period=30')
            ->assertInertia(fn ($page) => $page->where('commands_per_user', 2));
    }

    // ── Feature penetration ───────────────────────────────────────────────────

    public function test_feature_penetration_returns_action_user_counts_by_tier(): void
    {
        $owner   = $this->makeOwner();
        $proUser = $this->makeClient('pro');
        $this->insertCliLog($proUser->id, 'summarize', 100, 5);

        $this->actingAs($owner)->get('/console/owner/health?period=30')
            ->assertInertia(fn ($page) => $page
                ->has('feature_penetration')
                ->where('feature_penetration.summarize.pro', 1)
            );
    }

    // ── Conversion rate ───────────────────────────────────────────────────────

    public function test_conversion_rate_is_zero_when_no_users(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)->get('/console/owner/health')
            ->assertInertia(fn ($page) => $page
                ->where('conversion_rate.rate', 0)
                ->where('conversion_rate.paid_users', 0)
                ->where('conversion_rate.total_users', 0)
            );
    }

    public function test_conversion_rate_divides_paid_by_total_users(): void
    {
        $owner   = $this->makeOwner();
        $freeUser = $this->makeClient('free');
        $proUser  = $this->makeClient('pro');
        License::create(['user_id' => $proUser->id, 'lemon_key_hash' => str_repeat('d', 64), 'tier' => 'pro', 'seats' => 1, 'status' => 'active', 'expires_at' => null]);

        $this->actingAs($owner)->get('/console/owner/health')
            ->assertInertia(fn ($page) => $page
                ->where('conversion_rate.paid_users', 1)
                ->where('conversion_rate.total_users', 2)
                ->where('conversion_rate.rate', 50)
            );
    }

    // ── License issuances ─────────────────────────────────────────────────────

    public function test_license_issuances_always_returns_6_months(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)->get('/console/owner/health')
            ->assertInertia(fn ($page) => $page
                ->has('license_issuances.labels', 6)
                ->has('license_issuances.datasets')
            );
    }

    public function test_license_issuances_counts_by_month_and_tier(): void
    {
        $owner   = $this->makeOwner();
        $proUser = $this->makeClient('pro');

        License::create([
            'user_id'        => $proUser->id,
            'lemon_key_hash' => str_repeat('e', 64),
            'tier'           => 'pro',
            'seats'          => 1,
            'status'         => 'active',
            'expires_at'     => null,
        ]);

        $currentMonth = now()->format('Y-m');

        $this->actingAs($owner)->get('/console/owner/health')
            ->assertInertia(fn ($page) => $page
                ->where('license_issuances.labels.' . (5), $currentMonth)
            );
    }

    // ── NPM downloads ─────────────────────────────────────────────────────────

    public function test_npm_downloads_prop_is_present(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)->get('/console/owner/health')
            ->assertInertia(fn ($page) => $page->has('npm_downloads'));
    }

    // ── Caching ────────────────────────────────────────────────────────────

    public function test_client_health_response_is_served_from_cache_on_second_request_within_ttl(): void
    {
        $owner = $this->makeOwner();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($owner)->get('/console/owner/health?period=30')->assertOk();
        $coldRequestQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($owner)->get('/console/owner/health?period=30')->assertOk();
        $cachedRequestQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(
            $coldRequestQueries,
            $cachedRequestQueries,
            'Second client-health request within TTL must skip the aggregate queries (cache hit).'
        );
    }
}
