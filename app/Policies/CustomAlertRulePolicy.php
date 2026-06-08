<?php

namespace App\Policies;

use App\Models\CustomAlertRule;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class CustomAlertRulePolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        return $user->is_owner ? true : null;
    }

    /** create — user must belong to a group (Team+ tier). */
    public function create(User $user): bool
    {
        return $user->groups()->exists();
    }

    /** update / delete — rule must belong to the user's group. */
    public function update(User $user, CustomAlertRule $rule): bool
    {
        return $this->isSameGroup($user, $rule->group_id);
    }

    public function delete(User $user, CustomAlertRule $rule): bool
    {
        return $this->isSameGroup($user, $rule->group_id);
    }

    private function isSameGroup(User $user, ?int $groupId): bool
    {
        $userGroupId = $user->groups()->first()?->id;
        return $userGroupId !== null && $groupId === $userGroupId;
    }
}
