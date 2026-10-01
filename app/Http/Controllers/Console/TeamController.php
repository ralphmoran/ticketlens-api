<?php

namespace App\Http\Controllers\Console;

use App\Models\TriageSnapshot;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TeamController
{
    public function index(Request $request): Response
    {
        $user   = $request->user();
        $groups = $user->groups()->with('users:id,name,email')->get(['groups.id', 'groups.name', 'groups.permissions']);

        $memberIds = $groups->flatMap(fn ($g) => $g->users->pluck('id'))->unique()->values();

        $lastPushByUser = TriageSnapshot::whereIn('user_id', $memberIds)
            ->selectRaw('user_id, MAX(captured_at) as last_push, MAX(ticket_count) as ticket_count')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        return Inertia::render('Console/Team', [
            'is_owner' => $user->is_owner,
            'groups'   => $groups->map(fn ($g) => [
                'id'          => $g->id,
                'name'        => $g->name,
                'permissions' => $g->permissions,
                'members'     => $g->users->map(fn ($u) => [
                    'id'           => $u->id,
                    'name'         => $u->name,
                    'email'        => $u->email,
                    'last_push'    => $this->toInstant($lastPushByUser->get($u->id)?->last_push),
                    'ticket_count' => $lastPushByUser->get($u->id)?->ticket_count ?? 0,
                ]),
            ]),
        ]);
    }

    // MAX() returns a bare "Y-m-d H:i:s"; JS reads that as browser-local time. Send an unambiguous instant.
    private function toInstant(?string $raw): ?string
    {
        return $raw === null ? null : Carbon::parse($raw)->toIso8601String();
    }
}
