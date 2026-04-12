<?php

namespace App\Http\Controllers\Console;

use App\Enums\Permission;
use App\Models\UsageLog;
use App\Services\PermissionService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $isFreeTier = $user->tier === 'free';

        if ($isFreeTier) {
            return Inertia::render('Console/Analytics', [
                'tier'  => 'free',
                'stats' => null,
                'daily' => [],
            ]);
        }

        $logs = UsageLog::where('user_id', $user->id)
            ->selectRaw('action, SUM(tokens_used) as total_tokens, COUNT(*) as call_count')
            ->groupBy('action')
            ->get();

        $daily = UsageLog::where('user_id', $user->id)
            ->where('created_at', '>=', now()->subDays(14))
            ->selectRaw('DATE(created_at) as date, SUM(tokens_used) as tokens, COUNT(*) as calls')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return Inertia::render('Console/Analytics', [
            'tier'  => $user->tier,
            'stats' => [
                'totalTokens' => (int) $logs->sum('total_tokens'),
                'totalCalls'  => (int) $logs->sum('call_count'),
                'byAction'    => $logs->pluck('total_tokens', 'action'),
            ],
            'daily' => $daily,
        ]);
    }
}
