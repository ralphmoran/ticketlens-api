<?php

namespace App\Policies;

use App\Models\SlackDigestSchedule;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class DigestSchedulePolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        return $user->is_owner ? true : null;
    }

    public function create(User $user): bool
    {
        return $user->groups()->exists();
    }

    public function update(User $user, SlackDigestSchedule $schedule): bool
    {
        return $this->isSameGroup($user, $schedule->group_id);
    }

    public function delete(User $user, SlackDigestSchedule $schedule): bool
    {
        return $this->isSameGroup($user, $schedule->group_id);
    }

    private function isSameGroup(User $user, ?int $groupId): bool
    {
        $userGroupId = $user->groups()->first()?->id;
        return $userGroupId !== null && $groupId === $userGroupId;
    }
}
