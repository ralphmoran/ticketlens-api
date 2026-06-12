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
        $cutoff = $period === 'all' ? null : now()->subDays(
            in_array((int) $period, self::ALLOWED_PERIODS) ? (int) $period : 30
        );

        $query = DB::table('usage_logs')->whereNotNull('metadata');
        if ($cutoff) {
            $query->where('created_at', '>=', $cutoff);
        }
        $logs = $query->get(['user_id', 'action', 'tokens_used', 'metadata', 'created_at']);

        return Inertia::render('Console/Owner/Insights', [
            'period'           => $period,
            'popular_commands' => $this->popularCommands($logs),
            'tokens_saved_total' => (int) $logs->sum('tokens_used'),
            'roi_per_account'  => $this->roiPerAccount($logs),
            'feature_adoption' => $this->featureAdoption($logs),
            'top_accounts'     => $this->topAccounts($logs),
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
        return User::whereIn('id', $userIds)->get(['id', 'email', 'tier'])->keyBy('id');
    }
}
