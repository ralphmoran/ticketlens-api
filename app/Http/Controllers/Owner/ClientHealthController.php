<?php

namespace App\Http\Controllers\Owner;

use App\Models\License;
use App\Models\User;
use App\Models\UsageLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ClientHealthController
{
    public function index(Request $request): Response
    {
        $requested = (int) $request->query('period', 30);
        $period    = in_array($requested, config('ticketlens.client_health_periods'), true) ? $requested : 30;

        $props = Cache::remember(
            "owner:clienthealth:v1:period:{$period}",
            config('ticketlens.owner_analytics_cache_ttl'),
            fn () => $this->buildProps($period),
        );

        return Inertia::render('Console/Owner/ClientHealth', $props);
    }

    private function buildProps(int $period): array
    {
        $now   = now();
        $start = $now->copy()->subDays($period);
        $prev  = $start->copy()->subDays($period);

        $ownerIds = User::where('is_owner', true)->pluck('id');

        return [
            'period'              => $period,
            'new_accounts'        => $this->newAccounts($ownerIds, $start),
            'churned_accounts'    => $this->churnedAccounts($ownerIds, $start, $prev),
            'at_risk_accounts'    => $this->atRiskAccounts($ownerIds),
            'never_pushed'        => $this->neverPushed($ownerIds),
            'arpu'                => $this->arpu($ownerIds),
            'seat_utilization'    => $this->seatUtilization($ownerIds),
            'license_expiry'      => $this->licenseExpiry($now),
            'commands_per_user'   => $this->commandsPerUser($ownerIds, $start),
            'feature_penetration' => $this->featurePenetration($ownerIds, $start),
            'conversion_rate'     => $this->conversionRate($ownerIds),
            'license_issuances'   => $this->licenseIssuances(),
            'npm_downloads'       => $this->npmDownloads($period),
        ];
    }

    private function newAccounts($ownerIds, $start): int
    {
        return User::whereNotIn('id', $ownerIds)
            ->where('created_at', '>=', $start)
            ->count();
    }

    private function churnedAccounts($ownerIds, $start, $prev): int
    {
        return (int) DB::table('usage_logs as prev')
            ->whereNotIn('prev.user_id', $ownerIds)
            ->where('prev.has_metadata', 1)
            ->whereBetween('prev.created_at', [$prev, $start])
            ->whereNotExists(fn ($q) => $q
                ->from('usage_logs as cur')
                ->whereColumn('cur.user_id', 'prev.user_id')
                ->where('cur.has_metadata', 1)
                ->where('cur.created_at', '>=', $start)
            )
            ->distinct()
            ->count('prev.user_id');
    }

    private function atRiskAccounts($ownerIds): array
    {
        $threshold = now()->subDays(14);

        $base = User::whereNotIn('id', $ownerIds)
            ->whereNull('suspended_at')
            ->whereNotExists(fn ($q) => $q
                ->from('usage_logs')
                ->whereColumn('usage_logs.user_id', 'users.id')
                ->where('usage_logs.has_metadata', 1)
                ->where('usage_logs.created_at', '>=', $threshold)
            );

        return [
            'count'    => $base->count(),
            // ->toArray() — see DashboardController::buildStats() comment: cached
            // props must be plain arrays, not raw Eloquent Collections.
            'accounts' => (clone $base)
                ->select('id', 'name', 'email', 'tier', 'created_at')
                ->orderBy('created_at', 'desc')
                ->limit(50)
                ->get()
                ->toArray(),
        ];
    }

    private function neverPushed($ownerIds): int
    {
        return User::whereNotIn('id', $ownerIds)
            ->whereNull('suspended_at')
            ->whereNotExists(fn ($q) => $q
                ->from('usage_logs')
                ->whereColumn('usage_logs.user_id', 'users.id')
                ->where('usage_logs.has_metadata', 1)
            )
            ->count();
    }

    private function arpu($ownerIds): float
    {
        $prices    = config('tiers.prices', []);
        $paidTiers = array_keys(array_filter($prices, fn ($p) => $p > 0));

        if (empty($paidTiers)) {
            return 0.0;
        }

        $licenses = License::whereHas('user', fn ($q) => $q->whereNotIn('id', $ownerIds))
            ->where('status', 'active')
            ->whereIn('tier', $paidTiers)
            ->get(['tier', 'seats']);

        if ($licenses->isEmpty()) {
            return 0.0;
        }

        $mrr        = $licenses->sum(fn ($l) => ($prices[$l->tier] ?? 0) * $l->seats);
        $uniqueUsers = $licenses->unique('user_id')->count();

        return round($mrr / $uniqueUsers, 2);
    }

    private function seatUtilization($ownerIds): array
    {
        $totalSeats = License::whereHas('user', fn ($q) => $q->whereNotIn('id', $ownerIds))
            ->where('status', 'active')
            ->sum('seats');

        $usedSeats = (int) DB::table('group_user')
            ->join('groups', 'groups.id', '=', 'group_user.group_id')
            ->whereNotIn('groups.owner_id', $ownerIds)
            ->distinct('group_user.user_id')
            ->count('group_user.user_id');

        return [
            'total' => (int) $totalSeats,
            'used'  => $usedSeats,
        ];
    }

    private function licenseExpiry($now): array
    {
        $row = License::where('status', 'active')
            ->whereBetween('expires_at', [$now, $now->copy()->addDays(90)])
            ->selectRaw(
                'SUM(expires_at <= ?) as soon_30, SUM(expires_at <= ?) as soon_60, COUNT(*) as soon_90',
                [$now->copy()->addDays(30), $now->copy()->addDays(60)]
            )
            ->first();

        return [
            'soon_30' => (int) ($row->soon_30 ?? 0),
            'soon_60' => (int) ($row->soon_60 ?? 0),
            'soon_90' => (int) ($row->soon_90 ?? 0),
        ];
    }

    private function commandsPerUser($ownerIds, $start): float
    {
        $rows = UsageLog::whereNotIn('user_id', $ownerIds)
            ->cliOrigin()
            ->where('created_at', '>=', $start)
            ->selectRaw('user_id, SUM(command_count) as cmd_count')
            ->groupBy('user_id')
            ->get();

        if ($rows->isEmpty()) {
            return 0.0;
        }

        return (float) round($rows->avg('cmd_count'), 2);
    }

    private function featurePenetration($ownerIds, $start): array
    {
        $rows = UsageLog::whereNotIn('usage_logs.user_id', $ownerIds)
            ->cliOrigin()
            ->where('usage_logs.created_at', '>=', $start)
            ->join('users', 'users.id', '=', 'usage_logs.user_id')
            ->select('usage_logs.action', 'users.tier', DB::raw('COUNT(DISTINCT usage_logs.user_id) as user_count'))
            ->groupBy('usage_logs.action', 'users.tier')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[$row->action][$row->tier] = $row->user_count;
        }

        return $result;
    }

    private function conversionRate($ownerIds): array
    {
        $totalUsers = User::whereNotIn('id', $ownerIds)->count();

        if ($totalUsers === 0) {
            return ['rate' => 0, 'paid_users' => 0, 'total_users' => 0];
        }

        $paidTiers = array_keys(array_filter(config('tiers.prices', []), fn ($p) => $p > 0));
        $paidUsers = empty($paidTiers) ? 0 : License::whereHas('user', fn ($q) => $q->whereNotIn('id', $ownerIds))
            ->where('status', 'active')
            ->whereIn('tier', $paidTiers)
            ->distinct('user_id')
            ->count('user_id');

        return [
            'rate'        => round($paidUsers / $totalUsers * 100, 1),
            'paid_users'  => $paidUsers,
            'total_users' => $totalUsers,
        ];
    }

    private function licenseIssuances(): array
    {
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $months[] = now()->subMonths($i)->format('Y-m');
        }

        $start = now()->subMonths(6)->startOfMonth();
        $rows  = License::where('created_at', '>=', $start)
            ->get(['created_at', 'tier']);

        $byTierMonth = [];
        foreach ($rows as $row) {
            $month = substr($row->created_at, 0, 7); // 'Y-m'
            $tier  = $row->tier;
            $byTierMonth[$tier][$month] = ($byTierMonth[$tier][$month] ?? 0) + 1;
        }

        $tierColors = ['free' => 'neutral', 'pro' => 'brand', 'team' => 'info'];
        $datasets   = [];
        foreach ($tierColors as $tier => $color) {
            $data = [];
            foreach ($months as $month) {
                $data[] = $byTierMonth[$tier][$month] ?? 0;
            }
            $datasets[] = ['label' => $tier, 'data' => $data, 'color' => $color];
        }

        return ['labels' => $months, 'datasets' => $datasets];
    }

    private function npmDownloads(int $period): ?int
    {
        return Cache::get("npm_downloads_{$period}");
    }
}
