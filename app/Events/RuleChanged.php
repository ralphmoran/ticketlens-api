<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RuleChanged implements ShouldBroadcastNow
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

    public function broadcastAs(): string
    {
        return 'rule.changed';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
