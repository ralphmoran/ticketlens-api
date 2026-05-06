<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(): Response
    {
        $teams = Group::with(['owner.license', 'users'])
            ->withCount('users as member_count')
            ->latest()
            ->get()
            ->map(fn (Group $g) => [
                'id'           => $g->id,
                'name'         => $g->name,
                'owner'        => $g->owner ? ['id' => $g->owner->id, 'name' => $g->owner->name, 'email' => $g->owner->email] : null,
                'member_count' => $g->member_count,
                'seats'        => $g->owner?->license?->seats ?? null,
                'created_at'   => $g->created_at,
            ]);

        return Inertia::render('Console/Owner/Teams/Index', [
            'teams' => $teams,
        ]);
    }

    public function show(Group $group): Response
    {
        $group->load(['owner.license', 'users']);

        return Inertia::render('Console/Owner/Teams/Show', [
            'team' => [
                'id'         => $group->id,
                'name'       => $group->name,
                'owner'      => $group->owner ? [
                    'id'    => $group->owner->id,
                    'name'  => $group->owner->name,
                    'email' => $group->owner->email,
                ] : null,
                'seats'      => $group->owner?->license?->seats ?? null,
                'created_at' => $group->created_at,
            ],
            'members' => $group->users->map(fn (User $u) => [
                'id'    => $u->id,
                'name'  => $u->name,
                'email' => $u->email,
                'tier'  => $u->tier,
            ]),
        ]);
    }

    public function removeMember(Request $request, Group $group, User $user): RedirectResponse
    {
        abort_if($group->owner_id === $user->id, 422, 'Cannot remove the team owner from their own group.');

        $group->users()->detach($user->id);

        $this->audit->logFromRequest($request, 'team.member_removed', $user, null, [
            'group_id'   => $group->id,
            'group_name' => $group->name,
        ]);

        return redirect()->route('console.owner.teams.show', $group);
    }
}
