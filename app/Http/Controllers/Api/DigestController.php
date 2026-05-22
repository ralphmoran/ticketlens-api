<?php
namespace App\Http\Controllers\Api;

use App\Http\Requests\DigestDeliverRequest;
use App\Jobs\SendDigestEmail;
use App\Models\DigestSchedule;
use Illuminate\Http\JsonResponse;

class DigestController
{
    public function deliver(DigestDeliverRequest $request): JsonResponse
    {
        abort_unless($request->bearerToken(), 401);

        $hash = DigestSchedule::hashKey($request->bearerToken());
        $schedule = DigestSchedule::where('license_key_hash', $hash)
            ->where('active', true)
            ->firstOrFail();

        // Dispatch first — only record delivery time if the queue accepts the job.
        // Writing the timestamp before dispatch would poison the cooldown window on
        // queue-driver failure, preventing legitimate retry attempts.
        SendDigestEmail::dispatch($schedule->id, $request->validated());
        $schedule->update(['last_delivered_at' => now()]);

        return response()->json(['delivered' => true]);
    }
}
