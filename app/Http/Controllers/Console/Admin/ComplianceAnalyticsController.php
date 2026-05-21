<?php

namespace App\Http\Controllers\Console\Admin;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\TriageSnapshot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ComplianceAnalyticsController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user->is_owner) {
            return $this->ownerIndex($request);
        }

        $group = $user->ownedGroup ?? $user->groups()->first();
        abort_unless($group !== null, 403, 'No team found.');

        return Inertia::render('Console/Admin/ComplianceAnalytics', array_merge(
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

        $managerId = (int) $request->query('manager_id', 0);

        if ($managerId <= 0) {
            return Inertia::render('Console/Admin/ComplianceAnalytics', [
                'owner_mode'       => true,
                'clients'          => $clients,
                'selected_manager' => null,
                'group_name'       => '',
                'gap_by_prefix'    => [],
                'gap_by_status'    => [],
                'weekly_trend'     => [],
                'total_checked'    => 0,
                'overall_gap_rate' => null,
                'avg_coverage'     => null,
                'last_updated'     => null,
            ]);
        }

        $manager = User::whereHas('ownedGroup')->find($managerId);

        if (! $manager) {
            return redirect('/console/admin/compliance-analytics');
        }

        return Inertia::render('Console/Admin/ComplianceAnalytics', array_merge(
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
        $memberIds = $group->members()->orderBy('users.name')->pluck('users.id');

        $snapshots = TriageSnapshot::whereIn('user_id', $memberIds)
            ->where('captured_at', '>=', now()->subDays(90))
            ->orderBy('captured_at')
            ->get(['user_id', 'tickets', 'captured_at']);

        $isChecked = fn ($t) => in_array($t['compliance_status'] ?? 'unknown', ['pass', 'gap']);

        // All tickets with a known compliance result (gap or pass only)
        $allTickets = $snapshots->flatMap(
            fn ($s) => collect($s->tickets ?? [])->filter($isChecked)
        );

        $summarize = function ($tickets, string $labelKey, string $labelValue): array {
            $gap   = $tickets->where('compliance_status', 'gap')->count();
            $pass  = $tickets->where('compliance_status', 'pass')->count();
            $total = $gap + $pass;

            return [
                $labelKey  => $labelValue,
                'total'    => $total,
                'gap'      => $gap,
                'pass'     => $pass,
                'gap_rate' => $total > 0 ? round($gap / $total * 100, 1) : 0.0,
            ];
        };

        $avgCoverageOf = function ($tickets) {
            $coverages = $tickets
                ->filter(fn ($t) => isset($t['compliance_coverage']) && $t['compliance_coverage'] !== null)
                ->pluck('compliance_coverage');

            return $coverages->isNotEmpty() ? round($coverages->avg(), 1) : null;
        };

        $gapByPrefix = $allTickets
            ->groupBy(function ($t) {
                $key = $t['key'] ?? '';
                return $key !== '' ? strtoupper(explode('-', $key)[0]) : 'UNKNOWN';
            })
            ->map(fn ($tickets, $prefix) => $summarize($tickets, 'prefix', $prefix) + [
                'avg_coverage' => $avgCoverageOf($tickets),
            ])
            ->sortByDesc('gap_rate')
            ->values();

        $gapByStatus = $allTickets
            ->groupBy(fn ($t) => mb_substr($t['status'] ?? 'Unknown', 0, 100))
            ->map(fn ($tickets, $status) => $summarize($tickets, 'status', $status))
            ->sortByDesc('gap_rate')
            ->values();

        $weeklyTrend = $snapshots
            ->flatMap(fn ($s) => collect($s->tickets ?? [])
                ->filter($isChecked)
                ->map(fn ($t) => array_merge($t, [
                    '_week' => Carbon::parse($s->captured_at)->startOfWeek()->toDateString(),
                ]))
            )
            ->groupBy('_week')
            ->map(fn ($tickets, $week) => $summarize($tickets, 'week', $week))
            ->sortKeys()
            ->values();

        $totalChecked = $allTickets->count();
        $totalGap     = $allTickets->where('compliance_status', 'gap')->count();

        return [
            'group_name'       => $group->name,
            'gap_by_prefix'    => $gapByPrefix,
            'gap_by_status'    => $gapByStatus,
            'weekly_trend'     => $weeklyTrend,
            'total_checked'    => $totalChecked,
            'overall_gap_rate' => $totalChecked > 0 ? round($totalGap / $totalChecked * 100, 1) : null,
            'avg_coverage'     => $avgCoverageOf($allTickets),
            'last_updated'     => $snapshots->max('captured_at')?->toIso8601String(),
        ];
    }
}
