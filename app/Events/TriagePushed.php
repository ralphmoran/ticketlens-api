<?php

namespace App\Events;

class TriagePushed extends GroupBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'triage.pushed';
    }
}
