<?php

namespace App\Http\Controllers\Console\Admin;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\SlackIntegration;
use App\Models\TrackerProfile;
use App\Models\TriageSnapshot;
use App\Models\User;
use App\Models\WorkflowRule;
use App\Services\SseEventService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RulesController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user->is_owner) {
            return $this->ownerIndex($request);
        }

        abort_if($user->tier === 'free', 403, 'Workflow rules require a Pro or higher plan.');

        $group = $user->ownedGroup ?? $user->groups()->first();
        abort_unless($group !== null, 403, 'No team found.');

        return Inertia::render('Console/Admin/Rules', array_merge(
            $this->buildRuleData($group),
            ['owner_mode' => false, 'clients' => [], 'selected_manager' => null],
        ));
    }

    private function ownerIndex(Request $request): Response|RedirectResponse
    {
        $clients = User::clientPickerOptions();

        $managerId = (int) $request->query('manager_id', 0);

        if ($managerId <= 0) {
            return Inertia::render('Console/Admin/Rules', [
                'owner_mode'          => true,
                'clients'             => $clients,
                'selected_manager'    => null,
                'stale_rule'          => null,
                'custom_rule'         => null,
                'known_statuses'      => [],
                'known_priorities'    => [],
                'known_labels'        => [],
                'slack_connected'     => false,
                'profiles'            => [],
                'unconnected_members' => [],
            ]);
        }

        $manager = User::whereHas('ownedGroup')->find($managerId);

        if (! $manager) {
            return redirect('/console/admin/rules');
        }

        return Inertia::render('Console/Admin/Rules', array_merge(
            $this->buildRuleData($manager->ownedGroup),
            [
                'owner_mode'       => true,
                'clients'          => $clients,
                'selected_manager' => ['id' => $manager->id, 'name' => $manager->name, 'email' => $manager->email],
            ],
        ));
    }

    private function buildRuleData(Group $group): array
    {
        $staleRule = WorkflowRule::where('group_id', $group->id)
            ->where('type', 'stale')
            ->first();

        $customRule = WorkflowRule::where('group_id', $group->id)
            ->where('type', 'custom')
            ->first();

        $roster    = $group->members()->get(['users.id', 'users.name']);
        $memberIds = $roster->pluck('id');

        $memberProfiles = TrackerProfile::whereIn('user_id', $memberIds)
            ->with('user:id,name,email')
            ->get(['id', 'user_id', 'name', 'known_statuses', 'ticket_prefixes']);

        $profileStatuses = $memberProfiles->flatMap(fn ($p) => $p->known_statuses ?? []);

        // Fetched once regardless of the known_statuses branch taken below —
        // priorities/labels have no per-profile cache column equivalent, so
        // they always need this, and reusing it for the status fallback
        // avoids a second identical query.
        $snapshotTickets = TriageSnapshot::whereIn('user_id', $memberIds)
            ->where('captured_at', '>=', now()->subDays(30))
            ->orderByDesc('captured_at')
            ->limit(20)
            ->get(['tickets']);

        if ($profileStatuses->isNotEmpty()) {
            $statuses = $profileStatuses->filter()->unique()->sort()->values();
        } else {
            $statuses = $snapshotTickets
                ->flatMap(fn ($s) => collect($s->tickets ?? [])->pluck('status'))
                ->filter()
                ->unique()
                ->sort()
                ->values();
        }

        $priorities = $snapshotTickets
            ->flatMap(fn ($s) => collect($s->tickets ?? [])->pluck('priority'))
            ->filter()
            ->unique()
            ->sort()
            ->values();

        $labels = $snapshotTickets
            ->flatMap(fn ($s) => collect($s->tickets ?? [])->flatMap(fn ($t) => $t['labels'] ?? []))
            ->filter()
            ->unique()
            ->sort()
            ->values();

        $slackConnected = SlackIntegration::where('group_id', $group->id)
            ->whereNotNull('channel_id')
            ->exists();

        $connectedUserIds = $memberProfiles->pluck('user_id')->unique();

        $unconnectedMembers = $roster
            ->whereNotIn('id', $connectedUserIds)
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])
            ->values();

        $profiles = $memberProfiles
            ->map(fn ($p) => [
                'id'              => $p->id,
                'name'            => $p->name,
                'owner_name'      => $p->user->name,
                'owner_email'     => $p->user->email,
                'known_statuses'  => collect($p->known_statuses ?? [])->unique()->values(),
                'ticket_prefixes' => collect($p->ticket_prefixes ?? [])->unique()->values(),
            ])
            ->values();

        return [
            'stale_rule'          => $staleRule ? [
                'id'      => $staleRule->id,
                'enabled' => $staleRule->enabled,
                'config'  => $staleRule->config,
            ] : null,
            'custom_rule'         => $customRule ? [
                'id'      => $customRule->id,
                'enabled' => $customRule->enabled,
                'config'  => $customRule->config,
            ] : null,
            'known_statuses'      => $statuses,
            'known_priorities'    => $priorities,
            'known_labels'        => $labels,
            'slack_connected'     => $slackConnected,
            'profiles'            => $profiles,
            'unconnected_members' => $unconnectedMembers,
        ];
    }

    private function resolveGroup(User $user, Request $request): Group
    {
        if ($user->is_owner) {
            $managerId = (int) $request->input('manager_id', 0);
            $manager   = User::whereHas('ownedGroup')->find($managerId);
            abort_unless($manager !== null, 422, 'No team selected.');
            return $manager->ownedGroup;
        }

        abort_if($user->tier === 'free', 403, 'Workflow rules require a Pro or higher plan.');
        $group = $user->ownedGroup ?? $user->groups()->first();
        abort_unless($group !== null, 403, 'No team found.');
        return $group;
    }

    public function saveStale(Request $request): RedirectResponse
    {
        $group = $this->resolveGroup($request->user(), $request);

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

        app(SseEventService::class)->publish($group->id, 'rule.changed', []);

        return back()->with(['success' => 'Stale rule saved.', 'rule_type' => 'stale']);
    }

    public function saveCustom(Request $request): RedirectResponse
    {
        $group = $this->resolveGroup($request->user(), $request);

        $validator = Validator::make($request->all(), [
            'enabled'                  => ['required', 'boolean'],
            'cooldown_hours'           => ['sometimes', 'integer', 'min:1', 'max:720'],
            'rules'                    => ['required', 'array', 'min:1', 'max:50'],
            'rules.*.action'           => ['required', Rule::in(['force-urgent', 'ignore', 'notify', 'schedule'])],
            'rules.*.match'            => ['required', 'array'],
            'rules.*.match.priority'   => ['nullable', 'string', 'max:100'],
            'rules.*.match.label'      => ['nullable', 'string', 'max:100'],
            'rules.*.match.status'     => ['nullable', 'string', 'max:100'],
            'rules.*.match.keyPrefix'  => ['nullable', 'string', 'max:100'],
            'rules.*.reason'           => ['nullable', 'string', 'max:255'],
        ]);

        $validator->after(function ($validator) use ($request, $group) {
            $slackConnected = SlackIntegration::where('group_id', $group->id)
                ->whereNotNull('channel_id')
                ->exists();

            foreach ($request->input('rules', []) as $i => $rule) {
                $match = array_filter($rule['match'] ?? [], fn ($v) => $v !== null && $v !== '');
                if (empty($match)) {
                    $validator->errors()->add("rules.{$i}.match", 'At least one match field is required.');
                }

                if (in_array($rule['action'] ?? null, ['notify', 'schedule'], true) && ! $slackConnected) {
                    $validator->errors()->add("rules.{$i}.action", 'Notify and schedule require a connected Slack workspace.');
                }
            }
        });

        $data = $validator->validate();

        $rules = array_map(function (array $rule): array {
            if (isset($rule['reason'])) {
                $rule['reason'] = preg_replace('/[\x00-\x1F\x7F]/', '', $rule['reason']);
            }
            return $rule;
        }, $data['rules']);

        WorkflowRule::updateOrCreate(
            ['group_id' => $group->id, 'type' => 'custom'],
            [
                'enabled' => $data['enabled'],
                'config'  => array_filter([
                    'rules'          => $rules,
                    'cooldown_hours' => $data['cooldown_hours'] ?? null,
                ], fn ($v) => $v !== null),
            ],
        );

        app(SseEventService::class)->publish($group->id, 'rule.changed', []);

        return back()->with(['success' => 'Custom rule saved.', 'rule_type' => 'custom']);
    }

    public function toggleCustom(Request $request): RedirectResponse
    {
        $group = $this->resolveGroup($request->user(), $request);
        $data  = $request->validate(['enabled' => ['required', 'boolean']]);

        $rule = WorkflowRule::where('group_id', $group->id)->where('type', 'custom')->first();
        abort_unless($rule !== null, 404, 'No custom rule exists to toggle.');

        $rule->update(['enabled' => $data['enabled']]);

        app(SseEventService::class)->publish($group->id, 'rule.changed', []);

        return back()->with([
            'success'   => $data['enabled'] ? 'Custom rule enabled.' : 'Custom rule disabled.',
            'rule_type' => 'custom',
        ]);
    }

    public function destroyCustom(Request $request): RedirectResponse
    {
        $group = $this->resolveGroup($request->user(), $request);

        WorkflowRule::where('group_id', $group->id)->where('type', 'custom')->delete();

        app(SseEventService::class)->publish($group->id, 'rule.changed', []);

        return back()->with(['success' => 'Custom rule removed.', 'rule_type' => 'custom']);
    }

    public function toggleStale(Request $request): RedirectResponse
    {
        $group = $this->resolveGroup($request->user(), $request);
        $data  = $request->validate(['enabled' => ['required', 'boolean']]);

        $rule = WorkflowRule::where('group_id', $group->id)->where('type', 'stale')->first();
        abort_unless($rule !== null, 404, 'No stale rule exists to toggle.');

        $rule->update(['enabled' => $data['enabled']]);

        app(SseEventService::class)->publish($group->id, 'rule.changed', []);

        return back()->with([
            'success'   => $data['enabled'] ? 'Stale rule enabled.' : 'Stale rule disabled.',
            'rule_type' => 'stale',
        ]);
    }

    public function destroyStale(Request $request): RedirectResponse
    {
        $group = $this->resolveGroup($request->user(), $request);

        WorkflowRule::where('group_id', $group->id)->where('type', 'stale')->delete();

        app(SseEventService::class)->publish($group->id, 'rule.changed', []);

        return back()->with(['success' => 'Stale rule removed.', 'rule_type' => 'stale']);
    }
}
