<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Shared shape for every event broadcast on a group's private SSE channel.
 * Subclasses only need to name their own `broadcastAs()` type string.
 */
abstract class GroupBroadcastEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int   $groupId,
        public readonly array $payload,
    ) {}

    public function broadcastOn(): array|Channel
    {
        return new PrivateChannel("group.{$this->groupId}");
    }

    abstract public function broadcastAs(): string;

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
