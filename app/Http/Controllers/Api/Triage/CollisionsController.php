<?php

namespace App\Http\Controllers\Api\Triage;

use App\Enums\Permission;
use App\Models\TriageSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CollisionsController
{
    public function __invoke(Request $request): JsonResponse
    {
        $user   = $request->user();
        $userId = $user->id;

        if (($user->permissions & Permission::AttentionQueue->value) === 0) {
            return response()->json(['error' => 'Insufficient permissions'], 403);
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

        $myBranches = array_slice($mySnapshot->git_branches, 0, 20);

        $collisions = [];
        foreach ($teammateSnapshots as $otherSnap) {
            $theirBranches = array_slice($otherSnap->git_branches, 0, 20);
            foreach ($myBranches as $myBranch) {
                $myFilesIndex = array_flip($myBranch['files'] ?? []);
                foreach ($theirBranches as $otherBranch) {
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
