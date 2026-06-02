<?php

namespace App\Http\Controllers\Api\Triage;

use App\Enums\Permission;
use App\Http\Requests\Triage\PushRequest;
use App\Jobs\EvaluateAlertsJob;
use App\Models\TriageSnapshot;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class PushController
{
    public function __invoke(PushRequest $request): JsonResponse
    {
        $user       = $request->user();
        $tickets    = $request->validated('tickets');
        $profile    = $request->validated('profile');
        $capturedAt = $request->validated('captured_at');

        if (($user->permissions & Permission::AttentionQueue->value) === 0) {
            return response()->json(['error' => 'Insufficient permissions'], 403);
        }

        $ticketCount = count($tickets);

        $data = [
            'tickets'      => $tickets,
            'ticket_count' => $ticketCount,
            'captured_at'  => $capturedAt,
        ];

        if ($request->has('git_branches')) {
            $data['git_branches'] = $request->validated('git_branches');
        }

        $capturedDate = Carbon::parse($capturedAt)->utc()->toDateString();

        // One row per user+profile+day: update if same day already exists, else create.
        $existing = TriageSnapshot::where('user_id', $user->id)
            ->where('profile', $profile)
            ->whereDate('captured_at', $capturedDate)
            ->latest('captured_at')
            ->first();

        // Prune rows older than 90 days on every push (not only on insert) so
        // all active profiles are cleaned up regardless of daily push frequency.
        TriageSnapshot::where('user_id', $user->id)
            ->where('profile', $profile)
            ->where('captured_at', '<', now()->subDays(90))
            ->delete();

        if ($existing) {
            $existing->update($data);
            $snapshot = $existing;
        } else {
            $snapshot = TriageSnapshot::create(array_merge(
                ['user_id' => $user->id, 'profile' => $profile],
                $data,
            ));
        }

        EvaluateAlertsJob::dispatch($user->id, $snapshot->id);

        return response()->json(['pushed' => true, 'ticket_count' => $ticketCount]);
    }
}
