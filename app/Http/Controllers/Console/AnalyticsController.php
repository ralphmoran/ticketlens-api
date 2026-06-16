<?php

namespace App\Http\Controllers\Console;

use App\Models\UsageLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        if ($user->is_owner) {
            // Owner view aggregates every client account, excluding the owner's own rows.
            return $this->render('owner', true, fn ($q) => $q->where('user_id', '!=', $user->id));
        }

        if ($user->tier === 'free') {
            return Inertia::render('Console/Analytics', [
                'tier'          => 'free',
                'stats'         => null,
                'daily'         => [],
                'is_owner_view' => false,
            ]);
        }

        return $this->render($user->tier, false, fn ($q) => $q->where('user_id', $user->id));
    }

    /**
     * Render the analytics page for a token-saving scope. The $scope closure
     * applies the user filter (single user, or all clients for the owner view).
     */
    private function render(string $tier, bool $isOwnerView, callable $scope): Response
    {
        $logs = UsageLog::query()
            ->tap($scope)
            ->whereNull('metadata')
            ->selectRaw('action, SUM(tokens_used) as total_tokens, COUNT(*) as call_count')
            ->groupBy('action')
            ->get();

        $daily = UsageLog::query()
            ->tap($scope)
            ->whereNull('metadata')
            ->where('created_at', '>=', now()->subDays(14))
            ->selectRaw('DATE(created_at) as date, SUM(tokens_used) as tokens, COUNT(*) as calls')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return Inertia::render('Console/Analytics', [
            'tier'          => $tier,
            'is_owner_view' => $isOwnerView,
            'stats'         => [
                'totalTokens' => (int) $logs->sum('total_tokens'),
                'totalCalls'  => (int) $logs->sum('call_count'),
                'byAction'    => $logs->pluck('total_tokens', 'action'),
            ],
            'daily' => $daily,
        ]);
    }
}
