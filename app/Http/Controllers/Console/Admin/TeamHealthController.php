<?php

namespace App\Http\Controllers\Console\Admin;

use App\Models\Group;
use App\Models\TriageSnapshot;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TeamHealthController
{
    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user->is_owner) {
            return $this->ownerIndex($request);
        }

        // Managers own their group; leads are members of their group.
        $group = $user->ownedGroup ?? $user->groups()->first();

        abort_unless($group !== null, 403, 'No team found.');

        return Inertia::render('Console/Admin/TeamHealth', array_merge(
            $this->computeData($group),
            ['owner_mode' => false, 'clients' => [], 'selected_manager' => null],
        ));
    }

    private function ownerIndex(Request $request): Response|RedirectResponse
    {
        $clients = User::whereHas('ownedGroup')
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])
            ->values();

        $managerId = $request->query('manager_id');

        if (! $managerId) {
            return Inertia::render('Console/Admin/TeamHealth', [
                'owner_mode'       => true,
                'clients'          => $clients,
                'selected_manager' => null,
                'group_name'       => '',
                'needs_response'   => [],
                'bottlenecks'      => [],
                'workload'         => [],
                'last_updated'     => null,
            ]);
        }

        $manager = User::whereHas('ownedGroup')->find($managerId);

        if (! $manager) {
            return redirect('/console/admin/team-health');
        }

        return Inertia::render('Console/Admin/TeamHealth', array_merge(
            $this->computeData($manager->ownedGroup),
            [
                'owner_mode'       => true,
                'clients'          => $clients,
                'selected_manager' => ['id' => $manager->id, 'name' => $manager->name, 'email' => $manager->email],
            ],
        ));
    }

    private function computeData(Group $group): array
    {
        $members = $group->members()
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.email']);

        $snapshots = TriageSnapshot::whereIn('user_id', $members->pluck('id'))
            ->orderByDesc('captured_at')
            ->get(['user_id', 'tickets', 'ticket_count', 'captured_at']);

        $snapshotsByUser = $snapshots->groupBy('user_id');
        $memberMap       = $members->keyBy('id');

        $allTickets = $snapshots->flatMap(fn ($snap) => collect($snap->tickets)
            ->map(fn ($t) => array_merge($t, ['_member_id' => $snap->user_id]))
        );

        $needsResponse = $allTickets
            ->filter(fn ($t) => in_array('needs-response', $t['flags'] ?? []))
            ->sortByDesc('attention_score')
            ->values()
            ->map(fn ($t) => [
                'key'             => $t['key'],
                'summary'         => $t['summary'],
                'status'          => $t['status'],
                'url'             => $t['url'],
                'attention_score' => $t['attention_score'] ?? null,
                'member_name'     => $memberMap[$t['_member_id']]->name ?? 'Unknown',
            ]);

        $bottlenecks = $allTickets
            ->groupBy('status')
            ->map(fn ($tickets, $status) => ['status' => ($status ?: 'Unknown'), 'count' => $tickets->count()])
            ->sortByDesc('count')
            ->values();

        $workload = $members->map(function ($member) use ($snapshotsByUser) {
            $memberSnaps      = $snapshotsByUser->get($member->id, collect());
            $memberTickets    = $memberSnaps->flatMap(fn ($s) => $s->tickets ?? []);
            $needsRespCount   = $memberTickets->filter(fn ($t) => in_array('needs-response', $t['flags'] ?? []))->count();
            $lastPush         = $memberSnaps->max('captured_at');

            return [
                'member_id'            => $member->id,
                'member_name'          => $member->name,
                'member_email'         => $member->email,
                'ticket_count'         => $memberTickets->count(),
                'needs_response_count' => $needsRespCount,
                'last_push'            => $lastPush?->toIso8601String(),
            ];
        })->sortBy([['ticket_count', 'desc'], ['member_name', 'asc']])->values();

        return [
            'group_name'     => $group->name,
            'needs_response' => $needsResponse,
            'bottlenecks'    => $bottlenecks,
            'workload'       => $workload,
            'last_updated'   => $snapshots->max('captured_at')?->toIso8601String(),
        ];
    }
}
