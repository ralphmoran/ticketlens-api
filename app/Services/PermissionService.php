<?php

namespace App\Services;

use App\Models\User;

class PermissionService
{
    /**
     * Return the effective permission bitmask for a user:
     * user's own permissions OR'd with all group permissions.
     */
    public function effective(User $user): int
    {
        $groupPermissions = $user->groups->reduce(
            fn (int $carry, $group) => $carry | $group->permissions,
            0,
        );

        return $user->permissions | $groupPermissions;
    }

    public function can(User $user, int $permission): bool
    {
        return ($this->effective($user) & $permission) !== 0;
    }
}
