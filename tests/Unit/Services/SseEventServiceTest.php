<?php

namespace Tests\Unit\Services;

use App\Events\NotificationUpdated;
use App\Events\RuleChanged;
use App\Services\SseEventService;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SseEventServiceTest extends TestCase
{
    public function test_publish_broadcasts_notification_updated_for_the_registered_type(): void
    {
        Event::fake([NotificationUpdated::class]);

        (new SseEventService())->publish(9, 'notification.updated', []);

        Event::assertDispatched(
            NotificationUpdated::class,
            fn (NotificationUpdated $e) => $e->groupId === 9 && $e->payload === [],
        );
    }

    public function test_publish_is_a_noop_for_an_unregistered_type(): void
    {
        Event::fake();

        (new SseEventService())->publish(9, 'some.unknown.type', []);

        Event::assertNotDispatched(RuleChanged::class);
        Event::assertNotDispatched(NotificationUpdated::class);
    }
}
