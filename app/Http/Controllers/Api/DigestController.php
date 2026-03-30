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
        $hash = DigestSchedule::hashKey($request->bearerToken());
        $schedule = DigestSchedule::where('license_key_hash', $hash)->firstOrFail();

        SendDigestEmail::dispatch($schedule->id, $request->validated());
        $schedule->update(['last_delivered_at' => now()]);

        return response()->json(['delivered' => true]);
    }
}
