<?php

namespace App\Http\Controllers\Console\Admin;

use App\Http\Controllers\Controller;
use App\Models\Group;
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
        $clients = User::whereHas('ownedGroup')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'tier'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'tier' => $u->tier])
            ->values();

        $managerId = (int) $request->query('manager_id', 0);

        if ($managerId <= 0) {
            return Inertia::render('Console/Admin/Rules', [
                'owner_mode'       => true,
                'clients'          => $clients,
                'selected_manager' => null,
                'stale_rule'       => null,
                'custom_rule'      => null,
                'known_statuses'   => [],
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

        return [
            'stale_rule'     => $staleRule ? [
                'id'      => $staleRule->id,
                'enabled' => $staleRule->enabled,
                'config'  => $staleRule->config,
            ] : null,
            'custom_rule'    => $customRule ? [
                'id'      => $customRule->id,
                'enabled' => $customRule->enabled,
                'config'  => $customRule->config,
            ] : null,
            'known_statuses' => $statuses,
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

        return back()->with('success', 'Stale rule saved.');
    }

    public function saveCustom(Request $request): RedirectResponse
    {
        $group = $this->resolveGroup($request->user(), $request);

        $validator = Validator::make($request->all(), [
            'enabled'                  => ['required', 'boolean'],
            'rules'                    => ['required', 'array', 'min:1', 'max:50'],
            'rules.*.action'           => ['required', Rule::in(['force-urgent', 'ignore'])],
            'rules.*.match'            => ['required', 'array'],
            'rules.*.match.priority'   => ['nullable', 'string', 'max:100'],
            'rules.*.match.label'      => ['nullable', 'string', 'max:100'],
            'rules.*.match.status'     => ['nullable', 'string', 'max:100'],
            'rules.*.match.keyPrefix'  => ['nullable', 'string', 'max:100'],
            'rules.*.reason'           => ['nullable', 'string', 'max:255'],
        ]);

        $validator->after(function ($validator) use ($request) {
            foreach ($request->input('rules', []) as $i => $rule) {
                $match = array_filter($rule['match'] ?? [], fn ($v) => $v !== null && $v !== '');
                if (empty($match)) {
                    $validator->errors()->add("rules.{$i}.match", 'At least one match field is required.');
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
                'config'  => ['rules' => $rules],
            ],
        );

        app(SseEventService::class)->publish($group->id, 'rule.changed', []);

        return back()->with('success', 'Custom rule saved.');
    }

    public function toggleCustom(Request $request): RedirectResponse
    {
        $group = $this->resolveGroup($request->user(), $request);
        $data  = $request->validate(['enabled' => ['required', 'boolean']]);

        $rule = WorkflowRule::where('group_id', $group->id)->where('type', 'custom')->first();
        abort_unless($rule !== null, 404, 'No custom rule exists to toggle.');

        $rule->update(['enabled' => $data['enabled']]);

        app(SseEventService::class)->publish($group->id, 'rule.changed', []);

        return back()->with('success', $data['enabled'] ? 'Custom rule enabled.' : 'Custom rule disabled.');
    }

    public function destroyCustom(Request $request): RedirectResponse
    {
        $group = $this->resolveGroup($request->user(), $request);

        WorkflowRule::where('group_id', $group->id)->where('type', 'custom')->delete();

        app(SseEventService::class)->publish($group->id, 'rule.changed', []);

        return back()->with('success', 'Custom rule removed.');
    }

    public function toggleStale(Request $request): RedirectResponse
    {
        $group = $this->resolveGroup($request->user(), $request);
        $data  = $request->validate(['enabled' => ['required', 'boolean']]);

        $rule = WorkflowRule::where('group_id', $group->id)->where('type', 'stale')->first();
        abort_unless($rule !== null, 404, 'No stale rule exists to toggle.');

        $rule->update(['enabled' => $data['enabled']]);

        app(SseEventService::class)->publish($group->id, 'rule.changed', []);

        return back()->with('success', $data['enabled'] ? 'Stale rule enabled.' : 'Stale rule disabled.');
    }

    public function destroyStale(Request $request): RedirectResponse
    {
        $group = $this->resolveGroup($request->user(), $request);

        WorkflowRule::where('group_id', $group->id)->where('type', 'stale')->delete();

        app(SseEventService::class)->publish($group->id, 'rule.changed', []);

        return back()->with('success', 'Stale rule removed.');
    }
}
