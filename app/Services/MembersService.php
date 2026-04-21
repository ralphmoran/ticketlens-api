<?php

namespace App\Services;

use App\Exceptions\SeatLimitReached;
use App\Models\Group;
use App\Models\License;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Team member management — invites via Laravel's password-reset flow.
 *
 * Rather than building a custom invite-token system, we create the user
 * immediately with an unusable random password and trigger the standard
 * Password::sendResetLink(). The invitation email IS the password-reset
 * email; clicking the link lets the recipient set their password and log in.
 *
 * Seat-limit enforcement is at service layer (bypass-proof). UI advisory
 * check is in Admin/Members.vue.
 */
class MembersService
{
    public function __construct(private readonly AuditService $audit) {}

    public function invite(User $manager, string $email, ?string $name = null): User
    {
        $group   = $manager->ownedGroup;
        $license = License::where('user_id', $manager->id)
            ->where('status', 'active')
            ->latest()
            ->firstOrFail();

        $seatsUsed = $group->members()->count();
        if ($seatsUsed >= $license->seats) {
            throw new SeatLimitReached($license->seats);
        }

        return DB::transaction(function () use ($manager, $group, $email, $name) {
            // Idempotent — if the user already exists, just attach to the group if not already a member.
            $user = User::where('email', $email)->first();

            if ($user === null) {
                $user = User::create([
                    'name'        => $name ?: explode('@', $email)[0],
                    'email'       => $email,
                    'password'    => Str::random(64), // unusable — forces reset flow
                    'tier'        => $manager->tier,  // inherit manager's tier
                    'permissions' => \App\Enums\Permission::team(),
                ]);
            }

            if (! $group->members()->where('users.id', $user->id)->exists()) {
                $group->members()->attach($user->id);
            }

            Password::broker()->sendResetLink(['email' => $user->email]);

            $this->audit->log(
                actor: $manager,
                action: 'team.member_invited',
                target: $user,
                metadata: ['group_id' => $group->id, 'email' => $email],
            );

            return $user;
        });
    }
}
