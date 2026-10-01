<?php

namespace App\Events;

class UsageRecorded extends GroupBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'usage.recorded';
    }
}
