<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

class SseEventService
{
    public function publish(int $groupId, string $type, array $payload): void
    {
        try {
            Redis::xAdd(
                "ticketlens:events:{$groupId}",
                '*',
                ['type' => $type, 'payload' => json_encode($payload)],
                200,
                true,
            );
        } catch (\Throwable $e) {
            // Fire-and-forget: Redis unavailable → event dropped, operation continues.
        }
    }
}
