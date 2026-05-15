<?php

namespace App\Http\Controllers\Console\Admin;

use App\Http\Controllers\Controller;
use App\Models\AlertSetting;
use App\Models\CustomAlertRule;
use App\Models\Group;
use App\Models\SlackDigestSchedule;
use App\Models\SlackIntegration;
use App\Services\SlackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AlertsController extends Controller
{
    public function index(Request $request): Response
    {
        $group    = $this->resolveGroup($request);
        $settings = $group ? AlertSetting::where('group_id', $group->id)->first() : null;
        $rules = $group
            ? CustomAlertRule::where('group_id', $group->id)->orderBy('created_at')
                ->paginate(25, ['*'], 'rules_page')
                ->through(fn ($r) => [
                    'id'           => $r->id,
                    'alert_type'   => $r->alert_type,
                    'integration'  => $r->integration,
                    'target_id'    => $r->target_id,
                    'target_label' => $r->target_label,
                    'enabled'      => $r->enabled,
                ])
            : new LengthAwarePaginator([], 0, 25, 1);

        $digestSchedules = $group
            ? SlackDigestSchedule::where('group_id', $group->id)->orderBy('day_of_week')->orderBy('deliver_at')
                ->paginate(25, ['*'], 'schedules_page')
                ->through(fn ($s) => [
                    'id'               => $s->id,
                    'day_of_week'      => $s->day_of_week,
                    'deliver_at'       => $s->deliver_at,
                    'timezone'         => $s->timezone,
                    'target_type'      => $s->target_type,
                    'target_id'        => $s->target_id,
                    'target_label'     => $s->target_label,
                    'active'           => $s->active,
                    'last_delivered_at'=> $s->last_delivered_at?->toIso8601String(),
                ])
            : new LengthAwarePaginator([], 0, 25, 1);

        $integration = $group
            ? SlackIntegration::where('group_id', $group->id)->whereNotNull('channel_id')->first()
            : null;

        return Inertia::render('Console/Admin/Alerts', [
            'group'        => $group ? ['id' => $group->id, 'name' => $group->name] : null,
            'slackChannel' => $integration ? ['id' => $integration->channel_id, 'name' => $integration->channel_name] : null,
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
            'rules'           => $rules,
            'digestSchedules' => $digestSchedules,
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

    public function saveChannelAlert(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channel_id'   => ['required', 'string', 'max:100'],
            'channel_name' => ['required', 'string', 'max:100'],
        ]);

        $group = $this->resolveGroup($request);
        abort_unless($group !== null, 404);

        $integration = SlackIntegration::where('group_id', $group->id)->first();
        if (! $integration) {
            return response()->json(['error' => 'No Slack integration connected for this team.'], 422);
        }

        $integration->update([
            'channel_id'   => $validated['channel_id'],
            'channel_name' => $validated['channel_name'],
        ]);

        return response()->json(['ok' => true]);
    }

    public function fetchChannels(Request $request): JsonResponse
    {
        $group = $this->resolveGroup($request);
        abort_unless($group !== null, 404);

        $integration = SlackIntegration::where('group_id', $group->id)->first();
        if (! $integration) {
            return response()->json(['error' => 'No Slack integration connected for this team.'], 422);
        }

        try {
            $channels = app(SlackService::class)->fetchChannels($integration->bot_token);
            return response()->json(['channels' => $channels]);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function testAlert(Request $request, string $alertType): JsonResponse
    {
        $group = $this->resolveGroup($request);
        abort_unless($group !== null, 404);

        $integration = SlackIntegration::where('group_id', $group->id)->whereNotNull('channel_id')->first();
        if (! $integration) {
            return response()->json(['error' => 'No Slack integration connected for this team.'], 422);
        }

        $labels = [
            'needs-response' => 'Needs Response',
            'aging'          => 'Aging',
            'compliance-gap' => 'Compliance Gap',
        ];

        try {
            app(SlackService::class)->postMessage(
                $integration->bot_token,
                $integration->channel_id,
                '🧪 *Test alert* — This is a test *' . ($labels[$alertType] ?? $alertType) . '* alert from TicketLens. Your integration is working correctly.',
            );
            return response()->json(['ok' => true]);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function testRule(Request $request, CustomAlertRule $rule): JsonResponse
    {
        $this->authorizeRule($request, $rule);

        $group = $this->resolveGroup($request);
        $integration = SlackIntegration::where('group_id', $group->id)->first();
        if (! $integration) {
            return response()->json(['error' => 'No Slack integration connected for this team.'], 422);
        }

        $labels = ['needs_response' => 'Needs Response', 'aging' => 'Aging', 'compliance_gap' => 'Compliance Gap'];
        $text   = '🧪 *Test alert* — This is a test *' . ($labels[$rule->alert_type] ?? $rule->alert_type) . '* alert from TicketLens sent to ' . $rule->target_label . '.';

        try {
            app(SlackService::class)->postDm($integration->bot_token, $rule->target_id, $text);
            return response()->json(['ok' => true]);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function testDigestSchedule(Request $request, SlackDigestSchedule $digestSchedule): JsonResponse
    {
        $this->authorizeDigestSchedule($request, $digestSchedule);

        $group = $this->resolveGroup($request);
        $integration = SlackIntegration::where('group_id', $group->id)->first();
        if (! $integration) {
            return response()->json(['error' => 'No Slack integration connected for this team.'], 422);
        }

        $text = '🧪 *Test digest* — This is a test digest from TicketLens sent to ' . $digestSchedule->target_label . '.';

        try {
            if ($digestSchedule->target_type === 'channel') {
                app(SlackService::class)->postMessage($integration->bot_token, $digestSchedule->target_id, $text);
            } else {
                app(SlackService::class)->postDm($integration->bot_token, $digestSchedule->target_id, $text);
            }
            return response()->json(['ok' => true]);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function storeDigestSchedule(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'day_of_week'     => ['required', 'integer', 'min:0', 'max:6'],
            'deliver_at'      => ['required', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'timezone'        => ['required', 'string', 'timezone'],
            'target_type'     => ['required', Rule::in(['channel', 'user'])],
            'targets'         => ['required', 'array', 'min:1'],
            'targets.*.id'    => ['required', 'string', 'max:100'],
            'targets.*.label' => ['required', 'string', 'max:100'],
        ]);

        $group = $this->resolveGroup($request);
        abort_unless($group !== null, 404);

        foreach ($validated['targets'] as $target) {
            SlackDigestSchedule::create([
                'group_id'     => $group->id,
                'day_of_week'  => $validated['day_of_week'],
                'deliver_at'   => $validated['deliver_at'],
                'timezone'     => $validated['timezone'],
                'target_type'  => $validated['target_type'],
                'target_id'    => $target['id'],
                'target_label' => $target['label'],
            ]);
        }

        return back();
    }

    public function toggleDigestSchedule(Request $request, SlackDigestSchedule $digestSchedule): RedirectResponse
    {
        $this->authorizeDigestSchedule($request, $digestSchedule);
        $validated = $request->validate(['active' => ['required', 'boolean']]);
        $digestSchedule->update(['active' => $validated['active']]);
        return back();
    }

    public function destroyDigestSchedule(Request $request, SlackDigestSchedule $digestSchedule): RedirectResponse
    {
        $this->authorizeDigestSchedule($request, $digestSchedule);
        $digestSchedule->delete();
        return back();
    }

    private function authorizeDigestSchedule(Request $request, SlackDigestSchedule $digestSchedule): void
    {
        $group = $this->resolveGroup($request);
        abort_unless($group !== null && $digestSchedule->group_id === $group->id, 403);
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
