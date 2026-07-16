<?php

namespace App\Http\Controllers\Api\Recall;

use App\Enums\Permission;
use App\Http\Requests\Recall\PushRequest;
use App\Services\PermissionService;
use App\Services\RecallSecretScanner;
use App\Services\RecallStorage;
use Illuminate\Http\JsonResponse;

class PushController
{
    public function __invoke(PushRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! app(PermissionService::class)->can($user, Permission::Recall->value)) {
            return response()->json(['error' => 'Recall is not enabled for your account'], 403);
        }

        $group = $user->ownedGroup ?? $user->groups()->first();
        if ($group === null) {
            return response()->json(['error' => 'No team found'], 403);
        }

        $scan = app(RecallSecretScanner::class)->scan($request->validated());
        if ($scan['rejected']) {
            return response()->json(['error' => 'Note rejected', 'reasons' => $scan['reasons']], 422);
        }

        $note = app(RecallStorage::class)->push($group, $user, $request->validated());

        return response()->json(['pushed' => true, 'id' => $note->id, 'status' => $note->status]);
    }
}
