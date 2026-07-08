<?php

namespace App\Http\Controllers\Owner;

use App\Models\License;
use App\Models\UsageLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
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

        $props = Cache::remember(
            "owner:insights:v1:days:{$days}",
            config('ticketlens.owner_analytics_cache_ttl'),
            fn () => $this->buildProps($period, $days),
        );

        return Inertia::render('Console/Owner/Insights', $props);
    }

    private function buildProps(string $period, int $days): array
    {
        $cutoff = now()->subDays($days);

        $totals          = $this->periodTotals($cutoff);
        $accountRows     = $this->accountAggregates($cutoff);
        $accountUserIds  = $accountRows->pluck('user_id');
        $users           = $this->usersByIds($accountUserIds);

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
            'roi_per_account'          => $this->roiPerAccount($accountRows, $users),
            'feature_adoption'         => $this->featureAdoption($cutoff),
            'top_accounts'             => $this->topAccounts($accountRows, $users),
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
     * Per-account aggregates for the period window: one row per distinct
     * active user_id (bounded by active-user count, not push volume), shared
     * by roiPerAccount() and topAccounts() to avoid querying twice.
     */
    private function accountAggregates(\Illuminate\Support\Carbon $cutoff): Collection
    {
        return $this->cliLogsQuery($cutoff)
            ->selectRaw('user_id, SUM(tokens_used) as tokens_saved, SUM(command_count) as commands_run')
            ->groupBy('user_id')
            ->get();
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

    private function roiPerAccount(Collection $accountRows, ?Collection $users): array
    {
        if ($users === null) {
            return [];
        }

        $prices = config('tiers.prices');
        $rate   = config('tiers.token_rate_per_million');

        return $accountRows
            ->map(function ($row) use ($users, $prices, $rate) {
                $user        = $users->get($row->user_id);
                $tokensSaved = (int) $row->tokens_saved;
                $estSavings  = round($tokensSaved / 1_000_000 * $rate, 4);
                $price       = $prices[$user?->tier] ?? 0;
                $roi         = $price > 0 ? round($estSavings / $price, 4) : null;

                return [
                    'user_id'          => $row->user_id,
                    'name'             => $user?->name,
                    'email'            => $user?->email,
                    'tier'             => $user?->tier,
                    'tokens_saved'     => $tokensSaved,
                    'estimated_savings'=> $estSavings,
                    'roi'              => $roi,
                ];
            })
            ->sortByDesc('tokens_saved')
            ->values()
            ->toArray();
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

    private function topAccounts(Collection $accountRows, ?Collection $users): array
    {
        if ($users === null) {
            return [];
        }

        return $accountRows
            ->map(fn ($row) => [
                'user_id'     => $row->user_id,
                'name'        => $users->get($row->user_id)?->name,
                'email'       => $users->get($row->user_id)?->email,
                'tier'        => $users->get($row->user_id)?->tier,
                'commands_run'=> (int) $row->commands_run,
                'tokens_saved'=> (int) $row->tokens_saved,
            ])
            ->sortByDesc('commands_run')
            ->values()
            ->toArray();
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
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get(['tier', 'seats'])
            ->sum(fn ($l) => ($prices[$l->tier] ?? 0) * $l->seats);
    }

    private function licensesByTier(): array
    {
        $prices = config('tiers.prices');

        return License::where('status', 'active')
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
     * Load users referenced by account aggregates, keyed by id. Returns null
     * when no user_ids are present so callers can short-circuit to an empty
     * result.
     */
    private function usersByIds(Collection $userIds): ?Collection
    {
        $ids = $userIds->unique()->values()->toArray();
        if (empty($ids)) {
            return null;
        }

        return User::whereIn('id', $ids)->get(['id', 'name', 'email', 'tier'])->keyBy('id');
    }
}
