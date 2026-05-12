<?php

namespace App\Http\Controllers\Console\Admin;

use App\Models\TriageSnapshot;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TeamHealthController
{
    public function index(Request $request): Response
    {
        $manager = $request->user();
        $group   = $manager->is_owner ? null : $manager->ownedGroup;

        $members = $group
            ? $group->members()->orderBy('users.name')->get(['users.id', 'users.name', 'users.email'])
            : \App\Models\User::whereHas('groups')->orderBy('name')->get(['id', 'name', 'email']);

        $groupName = $group?->name ?? 'All Teams';

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
            ->map(fn ($tickets, $status) => ['status' => $status, 'count' => $tickets->count()])
            ->sortByDesc('count')
            ->values();

        $workload = $members->map(function ($member) use ($snapshotsByUser) {
            $memberSnaps        = $snapshotsByUser->get($member->id, collect());
            $allMemberTickets   = $memberSnaps->flatMap(fn ($s) => $s->tickets ?? []);
            $needsResponseCount = $allMemberTickets
                ->filter(fn ($t) => in_array('needs-response', $t['flags'] ?? []))
                ->count();
            $lastPush = $memberSnaps->max('captured_at');

            return [
                'member_id'            => $member->id,
                'member_name'          => $member->name,
                'member_email'         => $member->email,
                'ticket_count'         => $allMemberTickets->count(),
                'needs_response_count' => $needsResponseCount,
                'last_push'            => $lastPush?->toIso8601String(),
            ];
        })->sortBy([['ticket_count', 'desc'], ['member_name', 'asc']])->values();

        return Inertia::render('Console/Admin/TeamHealth', [
            'group_name'    => $groupName,
            'needs_response'=> $needsResponse,
            'bottlenecks'   => $bottlenecks,
            'workload'      => $workload,
            'last_updated'  => $snapshots->max('captured_at')?->toIso8601String(),
        ]);
    }
}
