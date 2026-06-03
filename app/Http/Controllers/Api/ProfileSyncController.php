<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TrackerProfile;
use App\Models\User;
use App\Models\WorkflowRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileSyncController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user          = $request->user();
        $teamStaleRule = $this->resolveTeamStaleRule($user);

        $profiles = $user
            ->trackerProfiles()
            ->orderBy('name')
            ->get()
            ->map(function (TrackerProfile $p) use ($teamStaleRule): array {
                $arr = $p->toCliArray();
                // User-level override takes precedence; fall back to team rule
                if ($arr['stale_rule'] === null && $teamStaleRule !== null) {
                    $arr['stale_rule'] = $teamStaleRule;
                }
                return $arr;
            })
            ->values();

        return response()->json(['profiles' => $profiles]);
    }

    private function resolveTeamStaleRule(User $user): ?array
    {
        $group = $user->groups()->first();
        if (! $group) {
            return null;
        }

        $rule = WorkflowRule::where('group_id', $group->id)
            ->where('type', 'stale')
            ->where('enabled', true)
            ->first();

        return $rule?->config;
    }
}
