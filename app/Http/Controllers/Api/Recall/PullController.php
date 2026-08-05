<?php

namespace App\Http\Controllers\Api\Recall;

use App\Enums\Permission;
use App\Services\PermissionService;
use App\Services\RecallStorage;
use App\Services\RecallTeamResolver;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PullController
{
    public function __invoke(Request $request, RecallTeamResolver $teamResolver): JsonResponse
    {
        $user = $request->user();

        if (! app(PermissionService::class)->can($user, Permission::Recall->value)) {
            return response()->json(['error' => 'Recall is not enabled for your account'], 403);
        }

        // The caller's group is resolved from the authenticated user only — an
        // arbitrary group_id query param must never be accepted here, that would
        // be exactly the class of cross-tenant read this endpoint must never
        // allow. `group` (a name) is accepted, but ONLY ever matched against
        // this user's own memberships via RecallTeamResolver — never a global
        // Group lookup — so it can select among a multi-team user's own teams
        // without opening a cross-tenant read.
        $request->validate(['since' => ['sometimes', 'date'], 'group' => ['sometimes', 'nullable', 'string', 'max:200']]);
        // Empty string means "no explicit target" — same as omitting the param.
        $requestedGroup = $request->query('group') ?: null;
        $group = $teamResolver->resolveForUser($user, $requestedGroup);

        if ($group === null) {
            return $requestedGroup !== null
                ? response()->json(['error' => 'Unknown team'], 422)
                : response()->json(['notes' => [], 'deleted' => []]);
        }

        $since  = $request->query('since') ? Carbon::parse($request->query('since')) : null;
        $storage = app(RecallStorage::class);
        $notes  = $storage->pull($group, $since);
        $deleted = $storage->pullTombstones($group, $since);

        return response()->json([
            'notes' => $notes->map(fn ($note) => [
                'external_id' => $note->external_id,
                'title'       => $note->title,
                'aliases'     => $note->aliases,
                'tickets'     => $note->tickets,
                'tags'        => $note->tags,
                'author'      => $note->author?->name,
                'sources'     => $note->sources,
                'body'        => $note->body,
                'status'      => $note->status,
                'created'     => $note->created_at->toIso8601String(),
            ]),
            'deleted' => $deleted->map(fn ($note) => [
                'external_id' => $note->external_id,
                'tickets'     => $note->tickets,
            ]),
        ]);
    }
}
