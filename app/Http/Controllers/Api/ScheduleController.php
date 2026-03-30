<?php
namespace App\Http\Controllers\Api;

use App\Http\Requests\ScheduleRequest;
use App\Models\DigestSchedule;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduleController
{
    public function store(ScheduleRequest $request): JsonResponse
    {
        $hash = DigestSchedule::hashKey($request->bearerToken());
        $data = $request->validated();

        DigestSchedule::updateOrCreate(
            ['license_key_hash' => $hash],
            [
                'email'      => $data['email'],
                'timezone'   => $data['timezone'],
                'deliver_at' => $data['deliverAt'],
                'active'     => true,
            ]
        );

        return response()->json([
            'scheduled'    => true,
            'nextDelivery' => $this->nextDelivery($data['deliverAt'], $data['timezone'])->toIso8601String(),
        ], 201);
    }

    public function show(Request $request): JsonResponse
    {
        $hash = DigestSchedule::hashKey($request->bearerToken());
        $schedule = DigestSchedule::where('license_key_hash', $hash)->firstOrFail();

        return response()->json([
            'email'           => $schedule->email,
            'timezone'        => $schedule->timezone,
            'deliverAt'       => substr($schedule->deliver_at, 0, 5),
            'active'          => $schedule->active,
            'lastDeliveredAt' => $schedule->last_delivered_at?->toIso8601String(),
            'nextDelivery'    => $this->nextDelivery($schedule->deliver_at, $schedule->timezone)->toIso8601String(),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $hash = DigestSchedule::hashKey($request->bearerToken());
        DigestSchedule::where('license_key_hash', $hash)->delete();
        return response()->json(['deleted' => true]);
    }

    private function nextDelivery(string $deliverAt, string $timezone): Carbon
    {
        [$h, $m] = explode(':', substr($deliverAt, 0, 5));
        $tz   = new \DateTimeZone($timezone);
        $now  = Carbon::now($tz);
        $next = $now->copy()->setTime((int) $h, (int) $m, 0);
        if ($next->isPast()) {
            $next->addDay();
        }
        return $next;
    }
}
