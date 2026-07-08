<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $stats = Cache::remember(
            'owner:dashboard:v1',
            config('ticketlens.owner_analytics_cache_ttl'),
            fn () => $this->buildStats(),
        );

        return Inertia::render('Console/Owner/Dashboard', ['stats' => $stats]);
    }

    private function buildStats(): array
    {
        $totalUsers  = User::where('is_owner', false)->count();
        $suspended   = User::where('is_owner', false)->whereNotNull('suspended_at')->count();
        $activeUsers = DB::table('usage_logs')
            ->where('has_metadata', 1)
            ->where('created_at', '>=', now()->subDays(30))
            ->distinct()
            ->count('user_id');

        return [
            'total_users'          => $totalUsers,
            'suspended_users'      => $suspended,
            'active_users'         => $activeUsers,
            'recent_actions'       => AuditLog::latest()->limit(100)->with(['actor', 'targetUser'])->get(),
            'user_status_chart'    => [
                'labels' => ['Active', 'Inactive'],
                'data'   => [$activeUsers, max(0, $totalUsers - $activeUsers)],
            ],
            'account_status_chart' => [
                'labels' => ['Active', 'Suspended'],
                'data'   => [max(0, $totalUsers - $suspended), $suspended],
            ],
        ];
    }
}
