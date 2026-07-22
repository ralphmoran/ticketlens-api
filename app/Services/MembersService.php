<?php

namespace App\Services;

use App\Enums\Permission;
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
        // A manager can hold two active licenses at once: a real paid license
        // (Free/Pro always seats=1) and a Team-Access addon license (seats>=2).
        // The addon always has the higher seat count, so ordering by seats
        // descending deterministically picks it over "whichever is newest".
        $license = License::where('user_id', $manager->id)
            ->where('status', 'active')
            ->orderByDesc('seats')
            ->firstOrFail();

        return DB::transaction(function () use ($manager, $group, $email, $name, $license) {
            // Re-count inside the transaction with a lock so two concurrent invites
            // cannot both pass the seat check and both be inserted (TOCTOU fix).
            $seatsUsed = $group->members()->lockForUpdate()->count();
            if ($seatsUsed >= $license->seats) {
                throw new SeatLimitReached($license->seats);
            }
            // Idempotent — if the user already exists, just attach to the group if not already a member.
            $user = User::where('email', $email)->first();

            if ($user === null) {
                $user = User::create([
                    'name'     => $name ?: explode('@', $email)[0],
                    'email'    => $email,
                    'password' => Str::random(64), // unusable — forces reset flow
                ]);
                // tier and permissions are not mass-assignable — set directly.
                // email_verified_at is pre-set: the invite link is sent to this
                // address, so the invite itself proves email ownership.
                // Invitee inherits the inviting manager's own tier preset, never
                // a hardcoded Permission::team() — a Free/Pro manager (granted
                // Team Access) must never leak the full Team feature set to
                // teammates who never paid for it. Manager bits are never
                // included here; only the group owner ever has those.
                $user->tier              = $manager->tier;
                $user->permissions       = match ($manager->tier) {
                    'pro'                 => Permission::pro(),
                    'team', 'enterprise'  => Permission::team(),
                    default               => Permission::free(),
                };
                $user->email_verified_at = now();
                $user->invited_at        = now();
                $user->save();
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

    /**
     * Re-sends the invite/activation email to a pending member. Assumes the
     * caller has already verified $target belongs to $group and is still
     * pending (invited_at set, activated_at null) — this method does not
     * re-check either, matching MembersController's other member actions
     * (destroy/promote), which enforce tenant scope and state at the
     * controller layer. $group is passed in rather than re-derived via
     * $manager->ownedGroup since the controller already resolved it for its
     * own tenant check.
     */
    public function resend(User $manager, User $target, Group $group): string
    {
        $status = Password::broker()->sendResetLink(['email' => $target->email]);

        $this->audit->log(
            actor: $manager,
            action: 'team.invite_resent',
            target: $target,
            metadata: ['group_id' => $group->id, 'status' => $status],
        );

        return $status;
    }
}
