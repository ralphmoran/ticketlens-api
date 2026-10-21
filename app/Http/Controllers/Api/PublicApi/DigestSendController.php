<?php

namespace App\Http\Controllers\Api\PublicApi;

use App\Http\Requests\DigestDeliverRequest;
use App\Jobs\SendDigestEmail;
use App\Models\DigestSchedule;
use Illuminate\Http\JsonResponse;
use Throwable;

/** Sends a digest to every active schedule owned by the key's user, at most once per cooldown. */
class DigestSendController
{
    private const COOLDOWN_MINUTES = 5;

    public function __invoke(DigestDeliverRequest $request): JsonResponse
    {
        $schedules = DigestSchedule::where('user_id', $request->user()->id)
            ->where('active', true)
            ->get();

        if ($schedules->isEmpty()) {
            return response()->json(['error' => 'No active digest schedule'], 404);
        }

        $cutoff    = now()->subMinutes(self::COOLDOWN_MINUTES);
        $delivered = $schedules->filter(fn (DigestSchedule $schedule) => $this->deliver($schedule, $request->validated(), $cutoff));

        if ($delivered->isEmpty()) {
            return response()->json(['error' => 'Digest recently sent'], 429)
                ->header('Retry-After', (string) $this->retryAfterSeconds($schedules));
        }

        return response()->json(['delivered' => true, 'schedules' => $delivered->count()]);
    }

    /** Claims the cooldown slot atomically, then queues the job; the claim is undone if queueing fails. */
    private function deliver(DigestSchedule $schedule, array $digest, \Carbon\CarbonInterface $cutoff): bool
    {
        $previous = $schedule->last_delivered_at;

        $claimed = DigestSchedule::whereKey($schedule->id)
            ->where(fn ($q) => $q->whereNull('last_delivered_at')->orWhere('last_delivered_at', '<', $cutoff))
            ->update(['last_delivered_at' => now()]);

        if (! $claimed) {
            return false;
        }

        try {
            SendDigestEmail::dispatch($schedule->id, $digest);
        } catch (Throwable $e) {
            DigestSchedule::whereKey($schedule->id)->update(['last_delivered_at' => $previous]);

            throw $e;
        }

        return true;
    }

    private function retryAfterSeconds($schedules): int
    {
        $soonest = $schedules->min(fn (DigestSchedule $s) => $s->last_delivered_at?->getTimestamp() ?? 0);

        return max(1, ($soonest + self::COOLDOWN_MINUTES * 60) - now()->getTimestamp());
    }
}
