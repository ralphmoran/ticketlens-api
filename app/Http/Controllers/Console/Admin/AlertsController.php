<?php

namespace App\Http\Controllers\Console\Admin;

use App\Http\Controllers\Controller;
use App\Models\AlertSetting;
use App\Models\Group;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AlertsController extends Controller
{
    public function index(Request $request): Response
    {
        $group = $this->resolveGroup($request);

        $settings = $group
            ? AlertSetting::where('group_id', $group->id)->first()
            : null;

        return Inertia::render('Console/Admin/Alerts', [
            'group'    => $group ? ['id' => $group->id, 'name' => $group->name] : null,
            'settings' => $settings ? [
                'needs_response_enabled' => $settings->needs_response_enabled,
                'aging_enabled'          => $settings->aging_enabled,
            ] : [
                'needs_response_enabled' => false,
                'aging_enabled'          => false,
            ],
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'needs_response_enabled' => ['required', 'boolean'],
            'aging_enabled'          => ['required', 'boolean'],
        ]);

        $group = $this->resolveGroup($request);
        abort_unless($group !== null, 404);

        AlertSetting::updateOrCreate(
            ['group_id' => $group->id],
            $validated,
        );

        return back();
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
