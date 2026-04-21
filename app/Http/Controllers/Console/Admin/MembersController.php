<?php

namespace App\Http\Controllers\Console\Admin;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\License;
use App\Models\User;
use App\Services\AuditService;
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
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): Response
    {
        $group = $request->user()->ownedGroup;

        $members = $group->members()
            ->withPivot([])
            ->orderBy('users.email')
            ->get(['users.id', 'users.name', 'users.email', 'users.tier', 'users.created_at']);

        $license = License::where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->latest()
            ->first(['id', 'tier', 'seats', 'expires_at']);

        return Inertia::render('Console/Admin/Members', [
            'group'       => ['id' => $group->id, 'name' => $group->name],
            'members'     => $members,
            'seats_used'  => $members->count(),
            'seats_total' => $license?->seats ?? 1,
            'is_owner_of' => $group->owner_id,
        ]);
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $group = $request->user()->ownedGroup;

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

        $group->members()->detach($user->id);

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

        abort_unless($group->members()->where('users.id', $user->id)->exists(), 404);
        abort_if($user->id === $manager->id, 422, 'You are already the manager.');

        \DB::transaction(function () use ($manager, $user, $group) {
            // Swap owner_id
            $group->update(['owner_id' => $user->id]);

            // Grant manager bits to new owner, revoke from old
            $mask = Permission::teamManagerMask();
            $user->update(['permissions' => $user->permissions | $mask]);
            $manager->update(['permissions' => $manager->permissions & ~$mask]);
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
