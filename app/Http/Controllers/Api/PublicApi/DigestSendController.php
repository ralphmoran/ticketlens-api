<?php

namespace App\Http\Controllers\Api\PublicApi;

use App\Http\Requests\DigestDeliverRequest;
use App\Jobs\SendDigestEmail;
use App\Models\DigestSchedule;
use Illuminate\Http\JsonResponse;

/** Sends a digest to every active schedule owned by the key's user. */
class DigestSendController
{
    public function __invoke(DigestDeliverRequest $request): JsonResponse
    {
        $schedules = DigestSchedule::where('user_id', $request->user()->id)
            ->where('active', true)
            ->get();

        if ($schedules->isEmpty()) {
            return response()->json(['error' => 'No active digest schedule'], 404);
        }

        foreach ($schedules as $schedule) {
            // Dispatch first: the cooldown timestamp must not advance if the queue refuses the job.
            SendDigestEmail::dispatch($schedule->id, $request->validated());
            $schedule->update(['last_delivered_at' => now()]);
        }

        return response()->json(['delivered' => true, 'schedules' => $schedules->count()]);
    }
}
