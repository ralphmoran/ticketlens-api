<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserFeatureGrant;

class PermissionService
{
    /**
     * Return the effective permission bitmask for a user:
     * user's own permissions OR'd with group permissions OR'd with active grant bits.
     */
    public function effective(User $user): int
    {
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
        return ($this->effective($user) & $permission) !== 0;
    }
}
