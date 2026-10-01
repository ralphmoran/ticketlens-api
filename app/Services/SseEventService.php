<?php

namespace App\Services;

use App\Events\DigestChanged;
use App\Events\MembersChanged;
use App\Events\NotificationUpdated;
use App\Events\RuleChanged;
use App\Events\TriagePushed;
use App\Events\UsageRecorded;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastException;
use RuntimeException;

class SseEventService
{
    private const EVENT_MAP = [
        'rule.changed'          => RuleChanged::class,
        'triage.pushed'         => TriagePushed::class,
        'notification.updated' => NotificationUpdated::class,
        'members.changed'      => MembersChanged::class,
        'digest.changed'       => DigestChanged::class,
        'usage.recorded'       => UsageRecorded::class,
    ];

    /** Returns whether the event went out; false for an unknown type or an unreachable broadcaster. */
    public function publish(int $groupId, string $type, array $payload): bool
    {
        $eventClass = self::EVENT_MAP[$type] ?? null;

        if ($eventClass === null) {
            return false;
        }

        try {
            broadcast(new $eventClass($groupId, $payload));
        } catch (BroadcastException|RuntimeException $e) {
            // Fire-and-forget: broadcast unavailable → event dropped, operation continues.
            return false;
        }

        return true;
    }

    /**
     * For events that belong to a user, not a group: tell every group the user is in.
     * Stops at the first failure: a hung broadcaster costs one timeout per request, not one per group.
     */
    public function publishToUserGroups(User $user, string $type, array $payload = []): void
    {
        foreach ($user->groups()->pluck('groups.id') as $groupId) {
            if (! $this->publish((int) $groupId, $type, $payload)) {
                return;
            }
        }
    }
}
