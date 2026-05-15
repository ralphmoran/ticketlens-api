<?php

namespace App\Http\Controllers\Console\Admin;

use App\Http\Controllers\Controller;
use App\Models\AlertSetting;
use App\Models\CustomAlertRule;
use App\Models\Group;
use App\Models\SlackIntegration;
use App\Services\SlackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AlertsController extends Controller
{
    public function index(Request $request): Response
    {
        $group    = $this->resolveGroup($request);
        $settings = $group ? AlertSetting::where('group_id', $group->id)->first() : null;
        $rules    = $group
            ? CustomAlertRule::where('group_id', $group->id)->orderBy('created_at')->get()
                ->map(fn ($r) => [
                    'id'           => $r->id,
                    'alert_type'   => $r->alert_type,
                    'integration'  => $r->integration,
                    'target_id'    => $r->target_id,
                    'target_label' => $r->target_label,
                    'enabled'      => $r->enabled,
                ])
            : [];

        return Inertia::render('Console/Admin/Alerts', [
            'group'    => $group ? ['id' => $group->id, 'name' => $group->name] : null,
            'settings' => $settings ? [
                'needs_response_enabled'        => $settings->needs_response_enabled,
                'needs_response_cooldown_hours' => $settings->needs_response_cooldown_hours,
                'aging_enabled'                 => $settings->aging_enabled,
                'aging_cooldown_hours'          => $settings->aging_cooldown_hours,
                'compliance_gap_enabled'        => $settings->compliance_gap_enabled,
                'compliance_gap_cooldown_hours' => $settings->compliance_gap_cooldown_hours,
            ] : [
                'needs_response_enabled'        => false,
                'needs_response_cooldown_hours' => 4,
                'aging_enabled'                 => false,
                'aging_cooldown_hours'          => 24,
                'compliance_gap_enabled'        => false,
                'compliance_gap_cooldown_hours' => 24,
            ],
            'rules' => $rules,
        ]);
    }

    public function saveNeedsResponse(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'enabled'        => ['required', 'boolean'],
            'cooldown_hours' => ['required', 'integer', 'min:1', 'max:720'],
        ]);

        $group = $this->resolveGroup($request);
        abort_unless($group !== null, 404);

        AlertSetting::updateOrCreate(
            ['group_id' => $group->id],
            [
                'needs_response_enabled'        => $validated['enabled'],
                'needs_response_cooldown_hours' => $validated['cooldown_hours'],
            ],
        );

        return back();
    }

    public function saveAging(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'enabled'        => ['required', 'boolean'],
            'cooldown_hours' => ['required', 'integer', 'min:1', 'max:720'],
        ]);

        $group = $this->resolveGroup($request);
        abort_unless($group !== null, 404);

        AlertSetting::updateOrCreate(
            ['group_id' => $group->id],
            [
                'aging_enabled'        => $validated['enabled'],
                'aging_cooldown_hours' => $validated['cooldown_hours'],
            ],
        );

        return back();
    }

    public function saveComplianceGap(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'enabled'        => ['required', 'boolean'],
            'cooldown_hours' => ['required', 'integer', 'min:1', 'max:720'],
        ]);

        $group = $this->resolveGroup($request);
        abort_unless($group !== null, 404);

        AlertSetting::updateOrCreate(
            ['group_id' => $group->id],
            [
                'compliance_gap_enabled'        => $validated['enabled'],
                'compliance_gap_cooldown_hours' => $validated['cooldown_hours'],
            ],
        );

        return back();
    }

    public function fetchMembers(Request $request): JsonResponse
    {
        $group = $this->resolveGroup($request);
        abort_unless($group !== null, 404);

        $integration = SlackIntegration::where('group_id', $group->id)->first();
        if (! $integration) {
            return response()->json(['error' => 'No Slack integration connected for this team.'], 422);
        }

        try {
            $members = app(SlackService::class)->fetchMembers($integration->bot_token);
            return response()->json(['members' => $members]);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function storeRule(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'alert_type'      => ['required', Rule::in(['needs_response', 'aging', 'compliance_gap'])],
            'integration'     => ['sometimes', 'string', Rule::in(['slack'])],
            'targets'         => ['required', 'array', 'min:1'],
            'targets.*.id'    => ['required', 'string', 'max:100'],
            'targets.*.label' => ['required', 'string', 'max:100'],
        ]);

        $group = $this->resolveGroup($request);
        abort_unless($group !== null, 404);

        $integration = $validated['integration'] ?? 'slack';

        foreach ($validated['targets'] as $target) {
            CustomAlertRule::firstOrCreate(
                [
                    'group_id'    => $group->id,
                    'alert_type'  => $validated['alert_type'],
                    'integration' => $integration,
                    'target_id'   => $target['id'],
                ],
                ['target_label' => $target['label'], 'enabled' => true],
            );
        }

        return back();
    }

    public function toggleRule(Request $request, CustomAlertRule $rule): RedirectResponse
    {
        $this->authorizeRule($request, $rule);
        $validated = $request->validate(['enabled' => ['required', 'boolean']]);
        $rule->update(['enabled' => $validated['enabled']]);
        return back();
    }

    public function destroyRule(Request $request, CustomAlertRule $rule): RedirectResponse
    {
        $this->authorizeRule($request, $rule);
        $rule->delete();
        return back();
    }

    private function authorizeRule(Request $request, CustomAlertRule $rule): void
    {
        $group = $this->resolveGroup($request);
        abort_unless($group !== null && $rule->group_id === $group->id, 403);
    }

    private function resolveGroup(Request $request): ?Group
    {
        $user = $request->user();

        if ($user->is_owner) {
            $groupId = $request->integer('group_id');
            return $groupId ? Group::find($groupId) : null;
        }

        return $user->ownedGroup;
    }
}
