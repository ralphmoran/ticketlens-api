<?php

namespace App\Http\Controllers\Api\Triage;

use App\Enums\Permission;
use App\Http\Requests\Triage\PushRequest;
use App\Jobs\EvaluateAlertsJob;
use App\Models\License;
use App\Models\TriageSnapshot;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class PushController
{
    public function __invoke(PushRequest $request): JsonResponse
    {
        $keyHash = TriageSnapshot::hashKey($request->bearerToken());
        $tickets = $request->validated('tickets');

        $userId = License::where('lemon_key_hash', $keyHash)
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->value('user_id');

        if ($userId !== null) {
            $user = User::find($userId);
            if ($user && ($user->permissions & Permission::AttentionQueue->value) === 0) {
                return response()->json(['error' => 'Insufficient permissions'], 403);
            }
        }

        $snapshot = TriageSnapshot::updateOrCreate(
            ['license_key_hash' => $keyHash, 'profile' => $request->validated('profile')],
            [
                'user_id'      => $userId,
                'tickets'      => $tickets,
                'ticket_count' => count($tickets),
                'captured_at'  => $request->validated('captured_at'),
            ],
        );

        if ($userId !== null) {
            EvaluateAlertsJob::dispatch($userId, $snapshot->id);
        }

        return response()->json(['pushed' => true, 'ticket_count' => count($tickets)]);
    }
}
