<?php

namespace App\Services;

use App\Events\RuleChanged;
use App\Events\TriagePushed;
use Illuminate\Broadcasting\BroadcastException;
use RuntimeException;

class SseEventService
{
    private const EVENT_MAP = [
        'rule.changed'  => RuleChanged::class,
        'triage.pushed' => TriagePushed::class,
    ];

    public function publish(int $groupId, string $type, array $payload): void
    {
        $eventClass = self::EVENT_MAP[$type] ?? null;

        if ($eventClass === null) {
            return;
        }

        try {
            broadcast(new $eventClass($groupId, $payload));
        } catch (BroadcastException|RuntimeException $e) {
            // Fire-and-forget: broadcast unavailable → event dropped, operation continues.
        }
    }
}
