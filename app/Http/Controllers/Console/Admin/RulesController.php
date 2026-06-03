<?php

namespace App\Http\Controllers\Console\Admin;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\TriageSnapshot;
use App\Models\WorkflowRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RulesController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_if($user->tier === 'free' && ! $user->is_owner, 403, 'Workflow rules require a Pro or higher plan.');

        $group = $user->ownedGroup ?? $user->groups()->first();
        abort_unless($group !== null, 403, 'No team found.');

        $staleRule = WorkflowRule::where('group_id', $group->id)
            ->where('type', 'stale')
            ->first();

        // Prefer statuses already cached on tracker_profiles (populated by CLI on push).
        // Fall back to recent snapshots only when no profile cache is available yet.
        $memberIds = $group->members()->pluck('users.id');

        $profileStatuses = \App\Models\TrackerProfile::whereIn('user_id', $memberIds)
            ->whereNotNull('known_statuses')
            ->get(['known_statuses'])
            ->flatMap(fn ($p) => $p->known_statuses ?? []);

        if ($profileStatuses->isNotEmpty()) {
            $statuses = $profileStatuses->filter()->unique()->sort()->values();
        } else {
            $statuses = TriageSnapshot::whereIn('user_id', $memberIds)
                ->where('captured_at', '>=', now()->subDays(30))
                ->orderByDesc('captured_at')
                ->limit(20)
                ->get(['tickets'])
                ->flatMap(fn ($s) => collect($s->tickets ?? [])->pluck('status'))
                ->filter()
                ->unique()
                ->sort()
                ->values();
        }

        return Inertia::render('Console/Admin/Rules', [
            'stale_rule'     => $staleRule ? [
                'id'      => $staleRule->id,
                'enabled' => $staleRule->enabled,
                'config'  => $staleRule->config,
            ] : null,
            'known_statuses' => $statuses,
        ]);
    }

    public function saveStale(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user->tier === 'free' && ! $user->is_owner, 403, 'Workflow rules require a Pro or higher plan.');

        $group = $user->ownedGroup ?? $user->groups()->first();
        abort_unless($group !== null, 403, 'No team found.');

        $data = $request->validate([
            'enabled'    => ['required', 'boolean'],
            'stale_days' => ['required', 'integer', 'min:1', 'max:365'],
            'statuses'   => ['required', 'array', 'min:1'],
            'statuses.*' => ['required', 'string', 'max:100'],
        ]);

        WorkflowRule::updateOrCreate(
            ['group_id' => $group->id, 'type' => 'stale'],
            [
                'enabled' => $data['enabled'],
                'config'  => [
                    'stale_days' => $data['stale_days'],
                    'statuses'   => $data['statuses'],
                ],
            ],
        );

        return back()->with('success', 'Stale rule saved.');
    }

    public function toggleStale(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user->tier === 'free' && ! $user->is_owner, 403, 'Workflow rules require a Pro or higher plan.');

        $group = $user->ownedGroup ?? $user->groups()->first();
        abort_unless($group !== null, 403, 'No team found.');

        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        $rule = WorkflowRule::where('group_id', $group->id)->where('type', 'stale')->first();
        abort_unless($rule !== null, 404, 'No stale rule exists to toggle.');

        $rule->update(['enabled' => $data['enabled']]);

        return back()->with('success', $data['enabled'] ? 'Stale rule enabled.' : 'Stale rule disabled.');
    }

    public function destroyStale(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user->tier === 'free' && ! $user->is_owner, 403, 'Workflow rules require a Pro or higher plan.');

        $group = $user->ownedGroup ?? $user->groups()->first();
        abort_unless($group !== null, 403, 'No team found.');

        WorkflowRule::where('group_id', $group->id)->where('type', 'stale')->delete();

        return back()->with('success', 'Stale rule removed.');
    }
}
