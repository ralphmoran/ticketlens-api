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

        // Update timestamp before dispatch so the job sees the correct last_delivered_at
        $schedule->update(['last_delivered_at' => now()]);
        SendDigestEmail::dispatch($schedule->id, $request->validated());

        return response()->json(['delivered' => true]);
    }
}
