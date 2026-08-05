<?php

namespace App\Services;

use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Resolves which of the AUTHENTICATED user's own group memberships a CLI
 * Recall push/pull/profile-sync request targets. Always scoped to
 * $user->groups() (or ownedGroup) — never a global Group lookup — so an
 * explicit group id can only ever select a team the token's own user
 * actually belongs to, however it entered the system (a raw client-supplied
 * id resolves the exact same way a name would: found only if it's this
 * user's own membership, null otherwise).
 *
 * Distinct from ActiveGroupResolver, which resolves console/admin-context
 * requests and lets a platform owner impersonate an arbitrary group by raw
 * id. That trust model does not apply to CLI API tokens.
 */
class RecallTeamResolver
{
    /**
     * @param int|null $groupId Explicit target team id, or null to fall back
     *                          to the user's default group (owned group,
     *                          else first membership) — the same precedence
     *                          every existing caller already relies on.
     */
    public function resolveForUser(User $user, ?int $groupId): ?Group
    {
        if ($groupId !== null) {
            return $user->groups()->find($groupId);
        }

        return $user->ownedGroup ?? $user->groups()->first();
    }

    /**
     * @return Collection<int, Group> every group the user owns or belongs
     *                                 to, each with a role attribute set.
     *                                 Owning a group doesn't guarantee pivot
     *                                 membership (only Group::createForOwner
     *                                 auto-attaches it), so ownedGroup is
     *                                 merged in explicitly rather than
     *                                 assumed to already be in groups().
     */
    public function listForUser(User $user): Collection
    {
        $memberships = $user->groups;
        $owned       = $user->ownedGroup;

        if ($owned !== null && ! $memberships->contains('id', $owned->id)) {
            $memberships = $memberships->push($owned);
        }

        return $memberships->map(function (Group $group) use ($user) {
            $group->role = $group->owner_id === $user->id ? 'owner' : 'member';
            return $group;
        });
    }
}
