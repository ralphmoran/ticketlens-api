<?php

namespace App\Http\Controllers\Api\Recall;

use App\Http\Controllers\Controller;
use App\Models\RecallSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    /**
     * Effective Recall queue settings for the authenticated CLI user — the
     * manager's override for their group if one exists, else platform
     * defaults. Solo/no-team users always get defaults here: there is no
     * separate "no team" error response (unlike TeamJiraConfigController)
     * because every Recall-entitled account needs *some* effective value.
     */
    public function show(Request $request): JsonResponse
    {
        $group = $request->user()->groups()->first();

        $settings = $group
            ? RecallSettings::where('group_id', $group->id)->first()
            : null;

        return response()->json([
            ...($settings?->only(array_keys(RecallSettings::DEFAULTS)) ?? RecallSettings::DEFAULTS),
            'is_override' => $settings !== null,
            'updated_at'  => $settings?->updated_at?->toISOString(),
        ]);
    }
}
