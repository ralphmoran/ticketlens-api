<?php

namespace App\Events;

class NotificationUpdated extends GroupBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'notification.updated';
    }
}
