<?php

namespace App\Http\Controllers\Console\Admin;

use App\Enums\Permission;
use App\Exceptions\SeatLimitReached;
use App\Http\Controllers\Controller;
use App\Models\License;
use App\Models\User;
use App\Services\AuditService;
use App\Services\MembersService;
use App\Services\TeamAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Team-manager view of their own team members.
 * All queries are scoped to the manager's owned_group — cross-tenant access
 * returns 404 (not 403) to avoid leaking existence of other teams.
 */
class MembersController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly MembersService $members,
        private readonly TeamAccessService $teamAccess,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'name'  => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->members->invite($request->user(), $validated['email'], $validated['name'] ?? null);
        } catch (SeatLimitReached $e) {
            return back()->withErrors(['email' => $e->getMessage()]);
        }

        return back();
    }

    public function index(Request $request): Response|RedirectResponse
    {
        $group = $request->user()->ownedGroup;

        if ($group === null) {
            return redirect('/console/owner/teams');
        }

        $members = $group->members()
            ->orderBy('users.email')
            ->get(['users.id', 'users.name', 'users.email', 'users.tier', 'users.created_at', 'users.permissions'])
            ->map(fn ($m) => [
                'id'         => $m->id,
                'name'       => $m->name,
                'email'      => $m->email,
                'tier'       => $m->tier,
                'created_at' => $m->created_at,
                'role'       => match (true) {
                    $m->id === $group->owner_id => 'manager',
                    (bool) ($m->permissions & Permission::TeamViewHealth->value) => 'lead',
                    default => 'dev',
                },
            ]);

        // Same dual-license shape as MembersService::invite() — a Team-Access
        // addon license always has more seats than a real Free/Pro license,
        // so ordering by seats descending deterministically picks it.
        $license = License::where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->orderByDesc('seats')
            ->first(['id', 'tier', 'seats', 'expires_at']);

        return Inertia::render('Console/Admin/Members', [
            'group'       => ['id' => $group->id, 'name' => $group->name],
            'members'     => $members,
            'seats_used'  => $members->count(),
            'seats_total' => $license?->seats,
            'is_owner_of' => $group->owner_id,
        ]);
    }

    public function assignRole(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate(['role' => ['required', 'in:lead,dev']]);

        $group = $request->user()->ownedGroup;

        abort_unless($group !== null, 403);
        abort_unless($group->members()->where('users.id', $user->id)->exists(), 404);
        abort_if($user->id === $request->user()->id, 422, 'Cannot change your own role.');
        abort_if($group->owner_id === $user->id, 422, 'Manager role cannot be changed here.');

        $leadBit = Permission::TeamViewHealth->value;

        if ($validated['role'] === 'lead') {
            $user->permissions |= $leadBit;
        } else {
            $user->permissions &= ~$leadBit;
        }
        $user->save();

        $this->audit->log(
            actor: $request->user(),
            action: 'team.role_assigned',
            target: $user,
            metadata: ['group_id' => $group->id, 'role' => $validated['role']],
        );

        return back();
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $group = $request->user()->ownedGroup;

        abort_unless($group !== null, 403);
        // Tenant isolation — the target must be a member of this manager's group.
        // Using 404 not 403: don't leak that the user exists elsewhere.
        abort_unless($group->members()->where('users.id', $user->id)->exists(), 404);

        // Cannot remove yourself (the manager) via this endpoint — use demote-then-remove.
        if ($user->id === $request->user()->id) {
            abort(422, 'You cannot remove yourself. Transfer ownership first.');
        }

        // Cannot remove someone who is currently the group's owner (should be impossible
        // given we just excluded self, but defense-in-depth for a future promoted member).
        abort_if($group->owner_id === $user->id, 422, 'Cannot remove the team manager. Transfer ownership first.');

        \DB::transaction(function () use ($group, $user): void {
            $group->members()->detach($user->id);
            // Clear lead bit so a re-invited member doesn't inherit stale elevation.
            $user->permissions &= ~Permission::TeamViewHealth->value;
            $user->save();
        });

        $this->audit->log(
            actor: $request->user(),
            action: 'team.member_removed',
            target: $user,
            metadata: ['group_id' => $group->id],
        );

        return back();
    }

    /**
     * Transfer team manager role to another existing member.
     * Invariant: a Team group MUST have exactly one manager at all times —
     * the transfer is atomic (new manager gets bits, old manager loses them).
     */
    public function promote(Request $request, User $user): RedirectResponse
    {
        $manager = $request->user();
        $group   = $manager->ownedGroup;

        abort_unless($group !== null, 403);
        abort_unless($group->members()->where('users.id', $user->id)->exists(), 404);
        abort_if($user->id === $manager->id, 422, 'You are already the manager.');

        \DB::transaction(function () use ($manager, $user, $group) {
            // Swap owner_id
            $group->update(['owner_id' => $user->id]);

            // Exactly one mechanism grants the new owner access, matching
            // whichever the old owner actually had — never both, or a later
            // TeamAccessService::revoke() (grant-only) would leave a raw-bit
            // manager permanently un-revocable.
            $mask = Permission::teamManagerMask();
            $managerHasRawBits = ($manager->permissions & $mask) !== 0;

            if ($managerHasRawBits) {
                $user->permissions |= $mask;
                $user->save();
            }
            // Always stripped from the old owner — a no-op if they never had
            // the raw bits (grant-based managers never do).
            $manager->permissions &= ~$mask;
            $manager->save();

            // Grant-based path (Free/Pro Team Access managers) — unconditional,
            // a no-op when the old owner had no grants to move.
            $this->teamAccess->transferManagerGrant($manager, $user);
        });

        $this->audit->log(
            actor: $manager,
            action: 'team.manager_transferred',
            target: $user,
            metadata: ['group_id' => $group->id, 'from_user_id' => $manager->id],
        );

        return redirect()->route('console.dashboard');
    }
}
