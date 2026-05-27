<?php

namespace App\Http\Controllers\Api\Triage;

use App\Enums\Permission;
use App\Http\Requests\Triage\PushRequest;
use App\Jobs\EvaluateAlertsJob;
use App\Models\TriageSnapshot;
use Illuminate\Http\JsonResponse;

class PushController
{
    public function __invoke(PushRequest $request): JsonResponse
    {
        $user    = $request->user();
        $tickets = $request->validated('tickets');

        if (($user->permissions & Permission::AttentionQueue->value) === 0) {
            return response()->json(['error' => 'Insufficient permissions'], 403);
        }

        $data = [
            'user_id'      => $user->id,
            'tickets'      => $tickets,
            'ticket_count' => count($tickets),
            'captured_at'  => $request->validated('captured_at'),
        ];

        if ($request->has('git_branches')) {
            $data['git_branches'] = $request->validated('git_branches');
        }

        $snapshot = TriageSnapshot::updateOrCreate(
            ['user_id' => $user->id, 'profile' => $request->validated('profile')],
            $data,
        );

        EvaluateAlertsJob::dispatch($user->id, $snapshot->id);

        return response()->json(['pushed' => true, 'ticket_count' => count($tickets)]);
    }
}
