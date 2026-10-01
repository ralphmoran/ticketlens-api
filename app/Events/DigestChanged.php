<?php

namespace App\Events;

class DigestChanged extends GroupBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'digest.changed';
    }
}
