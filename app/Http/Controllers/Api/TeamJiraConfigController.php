<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TeamJiraConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeamJiraConfigController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user  = $request->user();
        $group = $user->groups()->first();

        if (! $group) {
            return response()->json(['error' => 'No team found.'], 404);
        }

        $config = TeamJiraConfig::where('group_id', $group->id)->first();

        if (! $config) {
            return response()->json(['error' => 'No team Jira config found.'], 404);
        }

        return response()->json([
            'group_name'      => $group->name,
            'jira_base_url'   => $config->jira_base_url,
            'auth_type'       => $config->auth_type,
            'prefixes'        => $config->prefixes,
            'project_paths'   => $config->project_paths,
            'triage_statuses' => $config->triage_statuses,
            'updated_at'      => $config->updated_at?->toISOString(),
        ]);
    }
}
