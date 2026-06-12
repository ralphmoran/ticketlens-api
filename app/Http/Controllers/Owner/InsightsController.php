<?php

namespace App\Http\Controllers\Owner;

use App\Models\License;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class InsightsController
{
    private const ALLOWED_PERIODS = [7, 30, 90];

    public function index(Request $request): Response
    {
        $period = $request->query('period', '30');
        $cutoff = $period === 'all' ? null : now()->subDays($this->daysForPeriod($period));

        $query = DB::table('usage_logs')->whereNotNull('metadata');
        if ($cutoff) {
            $query->where('created_at', '>=', $cutoff);
        }
        $logs = $query->get(['user_id', 'action', 'tokens_used', 'metadata', 'created_at']);

        [$prevTokensSaved, $prevActiveUsers] = $this->prevPeriodStats($period);

        return Inertia::render('Console/Owner/Insights', [
            'period'                   => $period,
            'popular_commands'         => $this->popularCommands($logs),
            'tokens_saved_total'       => (int) $logs->sum('tokens_used'),
            'roi_per_account'          => $this->roiPerAccount($logs),
            'feature_adoption'         => $this->featureAdoption($logs),
            'top_accounts'             => $this->topAccounts($logs),
            'tier_distribution'        => $this->tierDistribution(),
            'total_users'              => User::where('is_owner', false)->count(),
            'active_users'             => $logs->pluck('user_id')->unique()->count(),
            'monthly_revenue'          => $this->monthlyRevenue(),
            'licenses_by_tier'         => $this->licensesByTier(),
            'prev_period_tokens_saved' => $prevTokensSaved,
            'prev_period_active_users' => $prevActiveUsers,
        ]);
    }

    private function popularCommands(Collection $logs): array
    {
        return $logs->groupBy('action')
            ->map(fn ($rows, $action) => [
                'action'     => $action,
                'total_runs' => $this->sumCount($rows),
            ])
            ->sortByDesc('total_runs')
            ->values()
            ->toArray();
    }

    private function roiPerAccount(Collection $logs): array
    {
        $prices = config('tiers.prices');
        $rate   = config('tiers.token_rate_per_million');

        $users = $this->usersFromLogs($logs);
        if ($users === null) {
            return [];
        }

        return $logs->groupBy('user_id')
            ->map(function ($rows, $userId) use ($users, $prices, $rate) {
                $user        = $users->get($userId);
                $tokensSaved = (int) $rows->sum('tokens_used');
                $estSavings  = round($tokensSaved / 1_000_000 * $rate, 4);
                $price       = $prices[$user?->tier] ?? 0;
                $roi         = $price > 0 ? round($estSavings / $price, 4) : null;

                return [
                    'user_id'          => $userId,
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

    private function featureAdoption(Collection $logs): array
    {
        // Distinct users per action (MAU-style adoption count)
        return $logs->groupBy('action')
            ->map(fn ($rows) => $rows->pluck('user_id')->unique()->count())
            ->toArray();
    }

    private function topAccounts(Collection $logs): array
    {
        $users = $this->usersFromLogs($logs);
        if ($users === null) {
            return [];
        }

        return $logs->groupBy('user_id')
            ->map(function ($rows, $userId) use ($users) {
                $user = $users->get($userId);

                return [
                    'user_id'     => $userId,
                    'name'        => $user?->name,
                    'email'       => $user?->email,
                    'tier'        => $user?->tier,
                    'commands_run'=> $this->sumCount($rows),
                    'tokens_saved'=> (int) $rows->sum('tokens_used'),
                ];
            })
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
     * window immediately before the current period. Null for 'all' periods.
     *
     * @return array{int|null, int|null}
     */
    private function prevPeriodStats(string $period): array
    {
        if ($period === 'all') {
            return [null, null];
        }

        $days  = $this->daysForPeriod($period);
        $start = now()->subDays($days * 2);
        $end   = now()->subDays($days);

        $rows = DB::table('usage_logs')
            ->whereNotNull('metadata')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<',  $end)
            ->get(['user_id', 'tokens_used']);

        return [
            (int) $rows->sum('tokens_used'),
            $rows->pluck('user_id')->unique()->count(),
        ];
    }

    /**
     * Clamp a period string to a whitelisted day count, defaulting to 30.
     */
    private function daysForPeriod(string $period): int
    {
        return in_array((int) $period, self::ALLOWED_PERIODS) ? (int) $period : 30;
    }

    /**
     * Sum the per-row command counts stored in each log's JSON metadata.
     */
    private function sumCount(Collection $rows): int
    {
        return (int) $rows->sum(fn ($r) => json_decode($r->metadata, true)['count'] ?? 0);
    }

    /**
     * Load users referenced by the logs, keyed by id. Returns null when no
     * user_ids are present so callers can short-circuit to an empty result.
     */
    private function usersFromLogs(Collection $logs): ?Collection
    {
        $userIds = $logs->pluck('user_id')->unique()->values()->toArray();
        if (empty($userIds)) {
            return null;
        }

        // Eager-load to avoid N+1 across the per-account aggregation.
        return User::whereIn('id', $userIds)->get(['id', 'name', 'email', 'tier'])->keyBy('id');
    }
}
