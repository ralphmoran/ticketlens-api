<?php

namespace App\Http\Controllers\Api\Triage;

use App\Models\License;
use App\Models\TriageSnapshot;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CollisionsController
{
    public function __invoke(Request $request): JsonResponse
    {
        $keyHash = TriageSnapshot::hashKey($request->bearerToken());

        $userId = License::where('lemon_key_hash', $keyHash)
            ->where('status', 'active')
            ->where(fn($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->value('user_id');

        if (!$userId) {
            return response()->json(['collisions' => [], 'message' => 'License not linked to an account.']);
        }

        $user = User::find($userId);
        if (!$user) {
            return response()->json(['collisions' => [], 'message' => 'User not found.']);
        }

        $myGroupIds = $user->groups()->pluck('groups.id');
        if ($myGroupIds->isEmpty()) {
            return response()->json([
                'collisions' => [],
                'message'    => 'No team found. Collision detection requires a Team license and at least one group.',
            ]);
        }

        $teammateIds = DB::table('group_user')
            ->whereIn('group_id', $myGroupIds)
            ->where('user_id', '!=', $userId)
            ->pluck('user_id')
            ->unique();

        if ($teammateIds->isEmpty()) {
            return response()->json([
                'collisions' => [],
                'message'    => 'No teammates found. Have your team members join your group.',
            ]);
        }

        $mySnapshot = TriageSnapshot::where('user_id', $userId)
            ->whereNotNull('git_branches')
            ->where('captured_at', '>=', now()->subDays(7))
            ->orderBy('captured_at', 'desc')
            ->first();

        if (!$mySnapshot) {
            return response()->json([
                'collisions' => [],
                'message'    => 'No branch data found for you. Run `ticketlens triage --push` from a git repository.',
            ]);
        }

        $teammateSnapshots = TriageSnapshot::whereIn('user_id', $teammateIds)
            ->whereNotNull('git_branches')
            ->where('captured_at', '>=', now()->subDays(7))
            ->with('user')
            ->get()
            ->groupBy('user_id')
            ->map(fn($snaps) => $snaps->sortByDesc('captured_at')->first());

        $collisions = [];
        foreach ($teammateSnapshots as $otherSnap) {
            foreach ($mySnapshot->git_branches as $myBranch) {
                $myFiles      = $myBranch['files'] ?? [];
                $myFilesIndex = array_flip($myFiles);
                foreach ($otherSnap->git_branches as $otherBranch) {
                    $overlap = array_keys(array_intersect_key($myFilesIndex, array_flip($otherBranch['files'] ?? [])));
                    if (empty($overlap)) {
                        continue;
                    }
                    $collisions[] = [
                        'your_branch'   => $myBranch['branch'],
                        'your_tickets'  => $myBranch['tickets'] ?? [],
                        'teammate'      => $otherSnap->user?->name ?? 'Teammate',
                        'their_branch'  => $otherBranch['branch'],
                        'their_tickets' => $otherBranch['tickets'] ?? [],
                        'shared_files'  => $overlap,
                    ];
                }
            }
        }

        return response()->json(['collisions' => $collisions]);
    }
}
