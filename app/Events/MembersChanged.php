<?php

namespace App\Events;

class MembersChanged extends GroupBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'members.changed';
    }
}
