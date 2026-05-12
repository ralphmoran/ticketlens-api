<?php

namespace App\Http\Controllers\Console\Admin;

use App\Models\Group;
use App\Models\TriageSnapshot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProcessMetricsController
{
    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user->is_owner) {
            return $this->ownerIndex($request);
        }

        return Inertia::render('Console/Admin/ProcessMetrics', array_merge(
            $this->computeData($user->ownedGroup),
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
            return Inertia::render('Console/Admin/ProcessMetrics', [
                'owner_mode'       => true,
                'clients'          => $clients,
                'selected_manager' => null,
                'group_name'       => '',
                'velocity'         => [],
                'status_flow'      => [],
                'response_latency' => ['fresh' => 0, 'active' => 0, 'slowing' => 0, 'stale' => 0, 'abandoned' => 0, 'total' => 0],
                'compliance'       => [],
                'last_updated'     => null,
            ]);
        }

        $manager = User::whereHas('ownedGroup')->find($managerId);

        if (! $manager) {
            return redirect('/console/admin/process-metrics');
        }

        return Inertia::render('Console/Admin/ProcessMetrics', array_merge(
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
            ->get(['user_id', 'tickets', 'captured_at'])
            ->unique('user_id');

        $snapshotsByUser = $snapshots->groupBy('user_id');

        $ticketsByMember = $members->mapWithKeys(fn ($m) => [
            $m->id => $snapshotsByUser->get($m->id, collect())->flatMap(fn ($s) => $s->tickets ?? []),
        ]);

        $allTickets = $ticketsByMember->flatMap(
            fn ($tickets, $memberId) => $tickets->map(fn ($t) => array_merge($t, ['_member_id' => $memberId]))
        );

        $velocity = $members->map(function ($member) use ($ticketsByMember) {
            $memberTickets = $ticketsByMember->get($member->id, collect());
            $buckets = ['fresh' => 0, 'active' => 0, 'slowing' => 0, 'stale' => 0, 'abandoned' => 0];
            foreach ($memberTickets as $ticket) {
                $buckets[self::ageBucket($ticket['last_updated'] ?? null)]++;
            }
            return array_merge(
                ['member_id' => $member->id, 'member_name' => $member->name, 'total' => $memberTickets->count()],
                $buckets,
            );
        })->filter(fn ($row) => $row['total'] > 0)->sortByDesc('total')->values();

        $statusFlow = $allTickets
            ->groupBy('status')
            ->map(function ($tickets, $status) {
                $buckets = ['fresh' => 0, 'active' => 0, 'slowing' => 0, 'stale' => 0, 'abandoned' => 0];
                foreach ($tickets as $ticket) {
                    $buckets[self::ageBucket($ticket['last_updated'] ?? null)]++;
                }
                return array_merge(['status' => mb_substr($status ?? 'Unknown', 0, 100), 'total' => count($tickets)], $buckets);
            })
            ->sortByDesc('total')
            ->values();

        $needsResponse   = $allTickets->filter(fn ($t) => in_array('needs-response', $t['flags'] ?? []));
        $responseBuckets = ['fresh' => 0, 'active' => 0, 'slowing' => 0, 'stale' => 0, 'abandoned' => 0];
        foreach ($needsResponse as $ticket) {
            $responseBuckets[self::ageBucket($ticket['last_updated'] ?? null)]++;
        }
        $responseLatency = array_merge($responseBuckets, ['total' => $needsResponse->count()]);

        $compliance = $members->map(function ($member) use ($ticketsByMember) {
            $memberTickets = $ticketsByMember->get($member->id, collect());
            $total         = $memberTickets->count();
            $checked       = $memberTickets->filter(fn ($t) => ($t['compliance_status'] ?? 'unknown') !== 'unknown')->count();
            $coverages     = $memberTickets->filter(fn ($t) => isset($t['compliance_coverage']) && $t['compliance_coverage'] !== null)->pluck('compliance_coverage');
            return [
                'member_id'    => $member->id,
                'member_name'  => mb_substr($member->name, 0, 100),
                'total'        => $total,
                'checked'      => $checked,
                'coverage_pct' => $total > 0 ? round($checked / $total * 100, 1) : 0.0,
                'avg_coverage' => $coverages->isNotEmpty() ? round($coverages->avg(), 1) : null,
            ];
        })->sortBy('coverage_pct')->values();

        return [
            'group_name'       => $group->name,
            'velocity'         => $velocity,
            'status_flow'      => $statusFlow,
            'response_latency' => $responseLatency,
            'compliance'       => $compliance,
            'last_updated'     => $snapshots->max('captured_at')?->toIso8601String(),
        ];
    }

    private static function ageBucket(?string $lastUpdated): string
    {
        if ($lastUpdated === null) {
            return 'abandoned';
        }
        $days = abs(now()->diffInDays(Carbon::parse($lastUpdated)));
        if ($days < 1)  return 'fresh';
        if ($days < 3)  return 'active';
        if ($days < 7)  return 'slowing';
        if ($days < 14) return 'stale';
        return 'abandoned';
    }
}
