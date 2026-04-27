<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserFeatureGrant;

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

        $grantPermissions = UserFeatureGrant::where('user_id', $user->id)
            ->active()
            ->join('features', 'features.id', '=', 'user_feature_grants.feature_id')
            ->sum('features.bit_value');

        return $user->permissions | $groupPermissions | (int) $grantPermissions;
    }

    public function can(User $user, int $permission): bool
    {
        if ($user->is_owner) {
            return true;
        }

        return ($this->effective($user) & $permission) !== 0;
    }
}
