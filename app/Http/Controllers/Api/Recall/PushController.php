<?php

namespace App\Http\Controllers\Api\Recall;

use App\Enums\Permission;
use App\Exceptions\RecallAttachmentException;
use App\Http\Requests\Recall\PushRequest;
use App\Services\PermissionService;
use App\Services\RecallAttachmentStorage;
use App\Services\RecallSecretScanner;
use App\Services\RecallStorage;
use App\Services\RecallTeamResolver;
use Illuminate\Http\JsonResponse;

class PushController
{
    public function __invoke(PushRequest $request, RecallTeamResolver $teamResolver, RecallAttachmentStorage $attachmentStorage): JsonResponse
    {
        $user = $request->user();

        if (! app(PermissionService::class)->can($user, Permission::Recall->value)) {
            return response()->json(['error' => 'Recall is not enabled for your account'], 403);
        }

        $requestedGroupId = $request->validated('group_id');
        $group = $teamResolver->resolveForUser($user, $requestedGroupId);

        if ($group === null) {
            return $requestedGroupId !== null
                ? response()->json(['error' => 'Unknown team'], 422)
                : response()->json(['error' => 'No team found'], 403);
        }

        try {
            $decodedAttachments = $attachmentStorage->decode($request->validated('attachments') ?? []);
        } catch (RecallAttachmentException $e) {
            return response()->json(['error' => 'Invalid attachment', 'reason' => $e->getMessage()], 422);
        }

        $scanFields = $request->validated();
        $scanFields['attachment_texts'] = $attachmentStorage->textForScan($decodedAttachments);
        $scan = app(RecallSecretScanner::class)->scan($scanFields);
        if ($scan['rejected']) {
            return response()->json(['error' => 'Note rejected', 'reasons' => $scan['reasons']], 422);
        }

        $note = app(RecallStorage::class)->push($group, $user, $request->validated());

        try {
            $attachmentStorage->store($note, $decodedAttachments);
        } catch (RecallAttachmentException $e) {
            // The note itself is already saved at this point (title/body/tags) —
            // same precedent as AvatarService/AvatarProcessingException: a disk
            // write failure becomes a typed, catchable error here rather than an
            // uncaught 500. pushed:true is accurate (the note did save); the
            // client can tell attachments specifically failed and retry the same
            // push, which is safe — RecallStorage::push()'s upsert-by-external_id
            // and RecallAttachmentStorage::store()'s replace-wholesale semantics
            // make a retry idempotent.
            return response()->json(['pushed' => true, 'id' => $note->id, 'status' => $note->status, 'error' => 'Note saved, but attachment storage failed', 'reason' => $e->getMessage()], 500);
        }

        return response()->json(['pushed' => true, 'id' => $note->id, 'status' => $note->status, 'attachments' => count($decodedAttachments)]);
    }
}
