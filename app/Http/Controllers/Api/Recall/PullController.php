<?php

namespace App\Http\Controllers\Api\Recall;

use App\Enums\Permission;
use App\Services\PermissionService;
use App\Services\RecallStorage;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PullController
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! app(PermissionService::class)->can($user, Permission::Recall->value)) {
            return response()->json(['error' => 'Recall is not enabled for your account'], 403);
        }

        // The caller's group is resolved from the authenticated user only — a
        // group_id query param, if one were ever accepted here, would be exactly
        // the class of cross-tenant read this endpoint must never allow.
        $group = $user->ownedGroup ?? $user->groups()->first();
        if ($group === null) {
            return response()->json(['notes' => []]);
        }

        $request->validate(['since' => ['sometimes', 'date']]);
        $since = $request->query('since') ? Carbon::parse($request->query('since')) : null;
        $notes = app(RecallStorage::class)->pull($group, $since);

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
        ]);
    }
}
