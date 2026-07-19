<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\TrackerProfile;
use App\Models\User;
use App\Models\WorkflowRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileSyncController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user             = $request->user();
        $teamStaleRule    = $this->resolveTeamStaleRule($user);
        $teamCustomRules  = $this->resolveTeamCustomRules($user);

        $profiles = $user
            ->trackerProfiles()
            ->orderBy('name')
            ->get()
            ->map(function (TrackerProfile $p) use ($teamStaleRule, $teamCustomRules): array {
                $arr = $p->toCliArray();
                // User-level override takes precedence; fall back to team rule
                if ($arr['stale_rule'] === null && $teamStaleRule !== null) {
                    $arr['stale_rule'] = $teamStaleRule;
                }
                $arr['attention_rules'] = $teamCustomRules;
                return $arr;
            })
            ->values();

        return response()->json(['profiles' => $profiles]);
    }

    private function resolveGroup(User $user): ?Group
    {
        return $user->ownedGroup ?? $user->groups()->first();
    }

    private function resolveTeamStaleRule(User $user): ?array
    {
        $group = $this->resolveGroup($user);
        if (! $group) {
            return null;
        }

        $rule = WorkflowRule::where('group_id', $group->id)
            ->where('type', 'stale')
            ->where('enabled', true)
            ->first();

        return $rule?->config;
    }

    private function resolveTeamCustomRules(User $user): ?array
    {
        $group = $this->resolveGroup($user);
        if (! $group) {
            return null;
        }

        $rule = WorkflowRule::where('group_id', $group->id)
            ->where('type', 'custom')
            ->where('enabled', true)
            ->first();

        return $rule?->config['rules'] ?? null;
    }
}
