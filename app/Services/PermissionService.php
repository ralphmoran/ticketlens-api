<?php

namespace App\Services;

use App\Enums\Permission;
use App\Models\User;
use App\Models\UserFeatureGrant;
use Illuminate\Support\Collection;

class PermissionService
{
    /**
     * Maximum signed 32-bit integer — used as the owner's "all bits set" effective
     * bitmask so the value survives JSON-encoding to JavaScript. PHP_INT_MAX (64-bit)
     * loses precision through JS double coercion and breaks the front-end's `& bit`
     * checks — 0x7FFFFFFF fits in a JS int32 and AND-checks every known feature bit.
     */
    public const OWNER_GOD_BITMASK = 0x7FFFFFFF;

    /**
     * Return the effective permission bitmask for a user:
     * user's own permissions OR'd with group permissions OR'd with active grant bits.
     *
     * Platform owners short-circuit to OWNER_GOD_BITMASK (god mode) — their access is
     * granted by the `is_owner` flag, not by tier or grant bits, and the front-end
     * navigation also relies on this bitmask to render permission-gated items.
     */
    public function effective(User $user): int
    {
        if ($user->is_owner) {
            return self::OWNER_GOD_BITMASK;
        }

        $groupPermissions = $user->groups->reduce(
            fn (int $carry, $group) => $carry | $group->permissions,
            0,
        );

        // Use bitwise OR across all grant bit values. SUM is wrong here: if a feature
        // is granted twice (two rows), SUM double-counts the bit and corrupts the mask.
        $grantPermissions = UserFeatureGrant::where('user_id', $user->id)
            ->active()
            ->join('features', 'features.id', '=', 'user_feature_grants.feature_id')
            ->pluck('features.bit_value')
            ->reduce(fn (int $carry, int $bit) => $carry | $bit, 0);

        return $user->permissions | $groupPermissions | $grantPermissions;
    }

    /**
     * Same as effective() but accepts a pre-loaded grants collection (with 'feature' relation)
     * so callers that already fetched grants can avoid a second DB round-trip.
     */
    public function effectiveWithGrants(User $user, Collection $grants): int
    {
        if ($user->is_owner) {
            return self::OWNER_GOD_BITMASK;
        }

        $groupPermissions = $user->groups->reduce(
            fn (int $carry, $group) => $carry | $group->permissions,
            0,
        );

        $grantPermissions = $grants->reduce(
            fn (int $carry, $g) => $carry | ($g->feature->bit_value ?? 0),
            0,
        );

        return $user->permissions | $groupPermissions | $grantPermissions;
    }

    public function can(User $user, int $permission): bool
    {
        if ($user->is_owner) {
            return true;
        }

        return ($this->effective($user) & $permission) !== 0;
    }

    /**
     * Matches EnsureTeamManager middleware predicate: manager bit AND owned group.
     * Both are required — a bit without a group is meaningless (nothing to manage),
     * a group without the bit is revoked manager access. Owners are a platform
     * singleton whose role is orthogonal to team roles, guarded by is_owner directly
     * rather than by bitmask value (the bitmask is a derived consequence for owners).
     */
    public function isEffectiveTeamManager(User $user, int $effectivePermissions): bool
    {
        if ($user->is_owner) {
            return false;
        }

        $hasManagerBit = ($effectivePermissions & Permission::TeamManageMembers->value) !== 0;

        return $hasManagerBit && $user->isTeamManager();
    }
}
