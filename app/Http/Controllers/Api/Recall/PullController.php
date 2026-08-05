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

        // An explicit group_id IS accepted (for a multi-team account), but
        // ONLY ever matched against this user's own memberships via
        // RecallTeamResolver ($user->groups()->find($groupId)) — never a
        // global Group lookup — so it can never select a team this token's
        // user doesn't actually belong to. That's what makes accepting a
        // client-supplied id safe here, unlike a raw, unscoped group_id would be.
        $request->validate(['since' => ['sometimes', 'date'], 'group_id' => ['sometimes', 'nullable', 'integer']]);
        $requestedGroupId = $request->query('group_id') !== null ? (int) $request->query('group_id') : null;
        $group = $teamResolver->resolveForUser($user, $requestedGroupId);

        if ($group === null) {
            return $requestedGroupId !== null
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
