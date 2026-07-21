<?php

namespace App\Events;

class RuleChanged extends GroupBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'rule.changed';
    }
}
