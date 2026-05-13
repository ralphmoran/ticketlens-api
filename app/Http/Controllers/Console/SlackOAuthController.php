<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\SlackIntegration;
use App\Services\SlackService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SlackOAuthController extends Controller
{
    public function __construct(private readonly SlackService $slack) {}

    /** Redirect the user to Slack's authorization page. */
    public function redirect(Request $request): RedirectResponse
    {
        $groupId = $request->integer('group_id');
        abort_unless($groupId > 0 && Group::where('id', $groupId)->exists(), 422);

        // Owner can connect for any group; manager only for their own group.
        $user = $request->user();
        if (! $user->is_owner) {
            abort_unless($user->ownedGroup?->id === $groupId, 403);
        }

        return redirect($this->slack->buildAuthUrl($groupId));
    }

    /** Handle Slack's OAuth callback. */
    public function callback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            return redirect('/console/admin/integrations')
                ->with('error', 'Slack authorization was denied.');
        }

        try {
            $state   = $this->slack->decodeState($request->string('state'));
            $tokens  = $this->slack->exchangeCode($request->string('code'));
        } catch (\RuntimeException $e) {
            return redirect('/console/admin/integrations')
                ->with('error', $e->getMessage());
        }

        $groupId = $state['group_id'];
        $group   = Group::findOrFail($groupId);

        SlackIntegration::updateOrCreate(
            ['group_id' => $groupId],
            [
                'connected_by'   => $request->user()->id,
                'workspace_id'   => $tokens['workspace_id'],
                'workspace_name' => $tokens['workspace_name'],
                'bot_token'      => $tokens['bot_token'],
                'channel_id'     => null,
                'channel_name'   => null,
            ]
        );

        $returnUrl = $request->user()->is_owner
            ? '/console/admin/integrations?group_id=' . $groupId
            : '/console/admin/integrations';

        return redirect($returnUrl)->with('success', 'Slack connected to ' . $tokens['workspace_name'] . '.');
    }
}
