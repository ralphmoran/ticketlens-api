<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TriageSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatusCacheController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        // Prefer statuses already cached on tracker_profiles (set by CLI on push).
        // Fall back to last 20 snapshots when profiles have no cached statuses yet.
        $profileStatuses = $request->user()
            ->trackerProfiles()
            ->whereNotNull('known_statuses')
            ->get(['known_statuses'])
            ->flatMap(fn ($p) => $p->known_statuses ?? []);

        if ($profileStatuses->isNotEmpty()) {
            $statuses = $profileStatuses->filter()->unique()->sort()->values();
        } else {
            $statuses = TriageSnapshot::where('user_id', $userId)
                ->where('captured_at', '>=', now()->subDays(30))
                ->orderByDesc('captured_at')
                ->limit(20)
                ->get(['tickets'])
                ->flatMap(fn ($s) => collect($s->tickets ?? [])->pluck('status'))
                ->filter()
                ->unique()
                ->sort()
                ->values();
        }

        return response()->json(['statuses' => $statuses]);
    }
}
