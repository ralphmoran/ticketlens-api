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

        return redirect($this->slack->buildAuthUrl(
            $groupId,
            $user->id,
            (bool) $user->is_owner,
            popup:        $request->boolean('popup'),
            popupOrigin:  $this->safePopupOrigin($request->string('popup_origin')),
        ));
    }

    /**
     * Handle Slack's OAuth callback.
     *
     * This route runs WITHOUT an auth session — Slack redirects back via a different
     * domain (ngrok / production) so the browser won't carry the session cookie.
     * All necessary context (user_id, group_id, is_owner) lives in the encrypted state.
     */
    public function callback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            $state  = $this->tryDecodeState($request->string('state'));
            $origin = $this->safePopupOrigin($state['popup_origin'] ?? '');
            if (($state['popup'] ?? false) && $origin) {
                return redirect($origin . '/console/oauth-close?integration=slack&status=error&message=' . urlencode('Slack authorization was denied.'));
            }
            return redirect('/console/admin/integrations')
                ->with('error', 'Slack authorization was denied.');
        }

        try {
            $state  = $this->slack->decodeState($request->string('state'));
            $tokens = $this->slack->exchangeCode($request->string('code'));
        } catch (\RuntimeException $e) {
            return redirect('/console/admin/integrations')
                ->with('error', $e->getMessage());
        }

        $groupId = $state['group_id'];
        Group::findOrFail($groupId);

        SlackIntegration::updateOrCreate(
            ['group_id' => $groupId],
            [
                'connected_by'   => $state['user_id'],
                'workspace_id'   => $tokens['workspace_id'],
                'workspace_name' => $tokens['workspace_name'],
                'bot_token'      => $tokens['bot_token'],
                'channel_id'     => null,
                'channel_name'   => null,
            ]
        );

        $origin = $this->safePopupOrigin($state['popup_origin'] ?? '');
        if (($state['popup'] ?? false) && $origin) {
            return redirect($origin . '/console/oauth-close?integration=slack&status=success');
        }

        $returnUrl = $state['is_owner']
            ? '/console/admin/integrations?group_id=' . $groupId
            : '/console/admin/integrations';

        return redirect($returnUrl)->with('success', 'Slack connected to ' . $tokens['workspace_name'] . '.');
    }

    /** Best-effort state decode for the error path (state may be absent/tampered). */
    private function tryDecodeState(string $state): array
    {
        try {
            return $this->slack->decodeState($state);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Validate that an origin is http(s) — prevents javascript: injection.
     * Returns the origin unchanged, or '' if invalid.
     */
    private function safePopupOrigin(string $origin): string
    {
        return preg_match('#^https?://[^/]+$#', $origin) ? $origin : '';
    }
}
