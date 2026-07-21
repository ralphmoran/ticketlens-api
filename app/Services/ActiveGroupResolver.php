<?php

namespace App\Services;

use App\Models\Group;
use Illuminate\Http\Request;

/**
 * Resolves which group an admin-context request is acting on — a cross-cutting
 * concern, not owned by any one feature. Currently used by RecallController.
 * NotificationService does NOT use this: its Recall category only ever runs for
 * confirmed team managers (isEffectiveTeamManager() already excludes owners), so
 * it reads $user->ownedGroup directly rather than resolving an owner's ?group_id=
 * override — there is no owner-impersonation-of-another-group path in that code.
 */
class ActiveGroupResolver
{
    public function forRequest(Request $request): ?Group
    {
        $user = $request->user();

        if ($user->is_owner) {
            $groupId = $request->integer('group_id');
            return $groupId ? Group::find($groupId) : null;
        }

        return $user->ownedGroup ?? $user->groups()->first();
    }
}
