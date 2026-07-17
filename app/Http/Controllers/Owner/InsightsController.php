<?php

namespace App\Http\Controllers\Owner;

use App\Models\License;
use App\Models\UsageLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class InsightsController
{
    private const ALLOWED_PERIODS = [7, 14, 30, 60, 90];

    public function index(Request $request): Response
    {
        $period = $request->query('period', '30');
        $days   = $this->daysForPeriod($period);
        $cutoff = now()->subDays($days);

        $cachedProps = Cache::remember(
            "owner:insights:v1:days:{$days}",
            config('ticketlens.owner_analytics_cache_ttl'),
            fn () => $this->buildCachedProps($period, $days),
        );

        $accountsPerPage = $this->clampPerPage($request, 'accounts_per_page');
        $roiPerPage      = $this->clampPerPage($request, 'roi_per_page');
        $accountsSearch  = $request->string('accounts_search')->trim()->value() ?: null;
        $roiSearch       = $request->string('roi_search')->trim()->value() ?: null;

        $props = array_merge($cachedProps, [
            'top_accounts'    => $this->paginatedTopAccounts($cutoff, $accountsPerPage, $accountsSearch),
            'roi_per_account' => $this->paginatedRoiPerAccount($cutoff, $roiPerPage, $roiSearch),
            'filters'         => [
                'accounts_search'   => $accountsSearch,
                'accounts_per_page' => $accountsPerPage,
                'roi_search'        => $roiSearch,
                'roi_per_page'      => $roiPerPage,
            ],
        ]);

        return Inertia::render('Console/Owner/Insights', $props);
    }

    private function buildCachedProps(string $period, int $days): array
    {
        $cutoff = now()->subDays($days);

        $totals = $this->periodTotals($cutoff);

        [$prevTokensSaved, $prevActiveUsers] = $this->prevPeriodStats($period);
        $dailyRows = $this->dailyAggregates($period);

        return [
            // Canonical clamped value, not the raw request string — the response
            // is cached per $days bucket, so echoing the raw $period back here
            // would let one raw-string variant's label leak into every other
            // variant that clamps to the same bucket (e.g. "030" vs "30").
            'period'                   => (string) $days,
            'popular_commands'         => $this->popularCommands($cutoff),
            'tokens_saved_total'       => (int) ($totals->tokens_saved ?? 0),
            'feature_adoption'         => $this->featureAdoption($cutoff),
            'tier_distribution'        => $this->tierDistribution(),
            'total_users'              => User::where('is_owner', false)->count(),
            'active_users'             => (int) ($totals->active_users ?? 0),
            'monthly_revenue'          => $this->monthlyRevenue(),
            'licenses_by_tier'         => $this->licensesByTier(),
            'prev_period_tokens_saved' => $prevTokensSaved,
            'prev_period_active_users' => $prevActiveUsers,
            'tokens_saved_by_day'      => $this->fillDailySkeleton($period, $dailyRows, 'tokens'),
            'active_users_by_day'      => $this->fillDailySkeleton($period, $dailyRows, 'active'),
        ];
    }

    /**
     * Base query for CLI-origin usage_logs rows within a period window.
     * cliOrigin() is the dual-semantics discriminator (CLI rows have
     * has_metadata=1; BYOK/AI-action rows don't) — every metric query
     * shares this filter, extracted once so it can't drift between them.
     */
    private function cliLogsQuery(\Illuminate\Support\Carbon $cutoff): Builder
    {
        return UsageLog::query()
            ->cliOrigin()
            ->where('created_at', '>=', $cutoff);
    }

    private function periodTotals(\Illuminate\Support\Carbon $cutoff): object
    {
        return $this->cliLogsQuery($cutoff)
            ->selectRaw('SUM(tokens_used) as tokens_saved, COUNT(DISTINCT user_id) as active_users')
            ->first();
    }

    /**
     * Per-account aggregates for the period window, ungrouped result kept as
     * a query (not ->get()) so callers can joinSub() it against `users` and
     * paginate at the database level instead of loading every active account.
     */
    private function accountAggregatesSubquery(\Illuminate\Support\Carbon $cutoff): Builder
    {
        return $this->cliLogsQuery($cutoff)
            ->selectRaw('user_id, SUM(tokens_used) as tokens_saved, SUM(command_count) as commands_run')
            ->groupBy('user_id');
    }

    /**
     * Shared join between account aggregates and `users`, with an optional
     * name/email search — the common base for both paginated account tables.
     */
    private function searchableAccountsQuery(\Illuminate\Support\Carbon $cutoff, ?string $search): Builder
    {
        return User::query()
            ->withTrashed() // a soft-deleted client's past-period usage must still count toward owner-facing totals
            ->joinSub($this->accountAggregatesSubquery($cutoff), 'agg', 'agg.user_id', '=', 'users.id')
            ->when($search, fn (Builder $q) => $q->where(fn (Builder $q2) => $q2
                ->where('users.name', 'like', "%{$search}%")
                ->orWhere('users.email', 'like', "%{$search}%")))
            ->select(['users.id as user_id', 'users.name', 'users.email', 'users.tier', 'agg.tokens_saved', 'agg.commands_run']);
    }

    private function popularCommands(\Illuminate\Support\Carbon $cutoff): array
    {
        return $this->cliLogsQuery($cutoff)
            ->selectRaw('action, SUM(command_count) as total_runs')
            ->groupBy('action')
            ->orderByDesc('total_runs')
            ->get()
            ->map(fn ($row) => ['action' => $row->action, 'total_runs' => (int) $row->total_runs])
            ->toArray();
    }

    private function paginatedRoiPerAccount(\Illuminate\Support\Carbon $cutoff, int $perPage, ?string $search): LengthAwarePaginator
    {
        $prices = config('tiers.prices');
        $rate   = config('tiers.token_rate_per_million');

        return $this->searchableAccountsQuery($cutoff, $search)
            ->orderByDesc('agg.tokens_saved')
            ->orderBy('users.id') // deterministic tiebreaker — stable page boundaries when tokens_saved ties
            ->paginate($perPage, ['*'], 'roi_page')
            ->withQueryString()
            ->through(function ($row) use ($prices, $rate) {
                $tokensSaved = (int) $row->tokens_saved;
                $estSavings  = round($tokensSaved / 1_000_000 * $rate, 4);
                $price       = $prices[$row->tier] ?? 0;
                $roi         = $price > 0 ? round($estSavings / $price, 4) : null;

                return [
                    'user_id'           => $row->user_id,
                    'name'              => $row->name,
                    'email'             => $row->email,
                    'tier'              => $row->tier,
                    'tokens_saved'      => $tokensSaved,
                    'estimated_savings' => $estSavings,
                    'roi'               => $roi,
                ];
            });
    }

    private function featureAdoption(\Illuminate\Support\Carbon $cutoff): array
    {
        return $this->cliLogsQuery($cutoff)
            ->selectRaw('action, COUNT(DISTINCT user_id) as adopters')
            ->groupBy('action')
            ->pluck('adopters', 'action')
            ->map(fn ($v) => (int) $v)
            ->toArray();
    }

    private function paginatedTopAccounts(\Illuminate\Support\Carbon $cutoff, int $perPage, ?string $search): LengthAwarePaginator
    {
        return $this->searchableAccountsQuery($cutoff, $search)
            ->orderByDesc('agg.commands_run')
            ->orderBy('users.id') // deterministic tiebreaker — stable page boundaries when commands_run ties
            ->paginate($perPage, ['*'], 'accounts_page')
            ->withQueryString()
            ->through(fn ($row) => [
                'user_id'      => $row->user_id,
                'name'         => $row->name,
                'email'        => $row->email,
                'tier'         => $row->tier,
                'commands_run' => (int) $row->commands_run,
                'tokens_saved' => (int) $row->tokens_saved,
            ]);
    }

    private function tierDistribution(): array
    {
        return User::where('is_owner', false)
            ->selectRaw('tier, COUNT(*) as count')
            ->groupBy('tier')
            ->pluck('count', 'tier')
            ->map(fn ($v) => (int) $v)
            ->toArray();
    }

    private function monthlyRevenue(): float
    {
        $prices = config('tiers.prices');

        return (float) License::where('status', 'active')
            ->where('granted_by_owner_as_addon', false)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get(['tier', 'seats'])
            ->sum(fn ($l) => ($prices[$l->tier] ?? 0) * $l->seats);
    }

    private function licensesByTier(): array
    {
        $prices = config('tiers.prices');

        // granted_by_owner_as_addon licenses are comped seat capacity, not a
        // purchase — excluded so this never inflates reported MRR.
        return License::where('status', 'active')
            ->where('granted_by_owner_as_addon', false)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->selectRaw('tier, COUNT(*) as count, SUM(seats) as seats')
            ->groupBy('tier')
            ->get()
            ->map(fn ($r) => [
                'tier'       => $r->tier,
                'count'      => (int) $r->count,
                'unit_price' => $prices[$r->tier] ?? 0,
                'revenue'    => ($prices[$r->tier] ?? 0) * (int) $r->seats,
            ])
            ->sortByDesc('revenue')
            ->values()
            ->toArray();
    }

    /**
     * Returns [prev_period_tokens_saved, prev_period_active_users] for the
     * window immediately before the current period. Bounded aggregate query
     * — never fetches raw rows for the previous window either.
     *
     * @return array{int, int}
     */
    private function prevPeriodStats(string $period): array
    {
        $days  = $this->daysForPeriod($period);
        $start = now()->subDays($days * 2);
        $end   = now()->subDays($days);

        $totals = $this->cliLogsQuery($start)
            ->where('created_at', '<', $end)
            ->selectRaw('SUM(tokens_used) as tokens_saved, COUNT(DISTINCT user_id) as active_users')
            ->first();

        return [
            (int) ($totals->tokens_saved ?? 0),
            (int) ($totals->active_users ?? 0),
        ];
    }

    /**
     * Per-day tokens_saved + active_users for the current period window, in
     * one bounded GROUP BY DATE query (rows bounded by day count, not push
     * volume). Keyed by date string for skeleton merging.
     */
    private function dailyAggregates(string $period): Collection
    {
        $days  = $this->daysForPeriod($period);
        $start = now()->subDays($days - 1)->startOfDay();

        return $this->cliLogsQuery($start)
            ->selectRaw('DATE(created_at) as date, SUM(tokens_used) as tokens, COUNT(DISTINCT user_id) as active')
            ->groupBy('date')
            ->get()
            ->keyBy('date');
    }

    /**
     * Merges dailyAggregates() rows into a zero-filled daily skeleton for the
     * current period. $metric selects which aggregate column to project.
     *
     * @return array<int, array{date: string, value: int}>
     */
    private function fillDailySkeleton(string $period, Collection $dailyRows, string $metric): array
    {
        $days  = $this->daysForPeriod($period);
        $start = now()->subDays($days - 1)->startOfDay();

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i)->format('Y-m-d');
            $row  = $dailyRows->get($date);

            $value = match (true) {
                $row === null           => 0,
                $metric === 'tokens'    => (int) $row->tokens,
                $metric === 'active'    => (int) $row->active,
                default                 => 0,
            };

            $series[] = ['date' => $date, 'value' => $value];
        }

        return $series;
    }

    /**
     * Clamp a period string to a whitelisted day count, defaulting to 30.
     */
    private function daysForPeriod(string $period): int
    {
        return in_array((int) $period, self::ALLOWED_PERIODS) ? (int) $period : 30;
    }

    /**
     * Clamp a per_page request value to [1, 100] — shared by both account tables.
     */
    private function clampPerPage(Request $request, string $key): int
    {
        return min(max(1, (int) $request->input($key, 10)), 100);
    }
}
