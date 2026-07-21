<?php

namespace Tests\Unit\Events;

use App\Events\RuleChanged;
use App\Events\TriagePushed;
use Illuminate\Broadcasting\PrivateChannel;
use Tests\TestCase;

/**
 * Locks the shared broadcast contract (channel naming, payload passthrough)
 * before RuleChanged/TriagePushed are refactored to extend a common
 * GroupBroadcastEvent base — behaviour must be byte-identical after.
 */
class GroupBroadcastEventTest extends TestCase
{
    public function test_rule_changed_broadcasts_on_the_groups_private_channel(): void
    {
        $event   = new RuleChanged(42, ['foo' => 'bar']);
        $channel = $event->broadcastOn();

        $this->assertInstanceOf(PrivateChannel::class, $channel);
        $this->assertSame('private-group.42', $channel->name);
    }

    public function test_rule_changed_broadcast_as_is_rule_changed(): void
    {
        $this->assertSame('rule.changed', (new RuleChanged(1, []))->broadcastAs());
    }

    public function test_rule_changed_broadcast_with_returns_the_payload(): void
    {
        $this->assertSame(['a' => 1], (new RuleChanged(1, ['a' => 1]))->broadcastWith());
    }

    public function test_triage_pushed_broadcasts_on_the_groups_private_channel(): void
    {
        $event   = new TriagePushed(7, []);
        $channel = $event->broadcastOn();

        $this->assertInstanceOf(PrivateChannel::class, $channel);
        $this->assertSame('private-group.7', $channel->name);
    }

    public function test_triage_pushed_broadcast_as_is_triage_pushed(): void
    {
        $this->assertSame('triage.pushed', (new TriagePushed(1, []))->broadcastAs());
    }

    public function test_triage_pushed_broadcast_with_returns_the_payload(): void
    {
        $this->assertSame(['ticket_count' => 3], (new TriagePushed(1, ['ticket_count' => 3]))->broadcastWith());
    }
}
