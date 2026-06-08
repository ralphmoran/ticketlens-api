<?php

namespace App\Policies;

use App\Models\BriefTemplate;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class BriefTemplatePolicy
{
    use HandlesAuthorization;

    /** Owner bypasses all checks. */
    public function before(User $user, string $ability): ?bool
    {
        return $user->is_owner ? true : null;
    }

    /**
     * create — allowed for Pro+ or Team users (not Free).
     * The user must also belong to a group (enforced by the controller).
     */
    public function create(User $user): bool
    {
        return ! in_array($user->tier, ['free'], true)
            && $user->groups()->exists();
    }

    /**
     * update / delete — user must belong to the same group as the template,
     * and the template must not be a system template.
     * The null === null IDOR bypass is prevented by requiring $user->group to be non-null.
     */
    public function update(User $user, BriefTemplate $template): bool
    {
        return $this->canModify($user, $template);
    }

    public function delete(User $user, BriefTemplate $template): bool
    {
        return $this->canModify($user, $template);
    }

    private function canModify(User $user, BriefTemplate $template): bool
    {
        if ($template->is_system) {
            return false;
        }

        $userGroupId = $user->groups()->first()?->id;
        return $userGroupId !== null && $template->group_id === $userGroupId;
    }
}
