<?php

namespace App\Http\Controllers\Console\Admin;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\SlackIntegration;
use App\Services\SlackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationsController extends Controller
{
    public function __construct(private readonly SlackService $slack) {}

    public function index(Request $request): Response
    {
        $group = $this->resolveGroup($request);

        $integration = $group
            ? SlackIntegration::where('group_id', $group->id)->first()
            : null;

        return Inertia::render('Console/Admin/Integrations', [
            'group'       => $group ? ['id' => $group->id, 'name' => $group->name] : null,
            'integration' => $integration ? [
                'workspace_name' => $integration->workspace_name,
                'channel_id'     => $integration->channel_id,
                'channel_name'   => $integration->channel_name,
            ] : null,
            'connect_url' => $group
                ? $this->slack->buildAuthUrl($group->id, $request->user()->id, (bool) $request->user()->is_owner)
                : null,
        ]);
    }

    public function channels(Request $request): JsonResponse
    {
        $group = $this->resolveGroup($request);
        abort_unless($group !== null, 404);

        $integration = SlackIntegration::where('group_id', $group->id)->firstOrFail();

        try {
            $channels = $this->slack->fetchChannels($integration->bot_token);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json(['channels' => $channels]);
    }

    public function saveChannel(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'channel_id'   => ['required', 'string', 'max:20'],
            'channel_name' => ['required', 'string', 'max:80'],
        ]);

        $group = $this->resolveGroup($request);
        abort_unless($group !== null, 404);

        SlackIntegration::where('group_id', $group->id)
            ->update([
                'channel_id'   => $validated['channel_id'],
                'channel_name' => $validated['channel_name'],
            ]);

        return back();
    }

    public function sendTest(Request $request): JsonResponse
    {
        $group = $this->resolveGroup($request);
        abort_unless($group !== null, 404);

        $integration = SlackIntegration::where('group_id', $group->id)->firstOrFail();
        abort_unless($integration->channel_id !== null, 422);

        try {
            $this->slack->postMessage(
                $integration->bot_token,
                $integration->channel_id,
                ':white_check_mark: TicketLens is connected to *#' . $integration->channel_name . '*. Alert notifications are active.',
            );
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json(['ok' => true]);
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $group = $this->resolveGroup($request);
        abort_unless($group !== null, 404);

        SlackIntegration::where('group_id', $group->id)->delete();

        return back();
    }

    /**
     * Resolve the target group:
     * - Owner: uses ?group_id=X query param (any group)
     * - Manager: their own ownedGroup
     */
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
