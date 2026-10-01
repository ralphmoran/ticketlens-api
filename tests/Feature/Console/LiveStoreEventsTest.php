<?php

namespace Tests\Feature\Console;

use App\Events\DigestChanged;
use App\Events\MembersChanged;
use App\Events\RuleChanged;
use App\Events\UsageRecorded;
use App\Models\Group;
use App\Models\License;
use App\Models\SlackDigestSchedule;
use App\Models\UsageLog;
use App\Models\User;
use App\Services\SseEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class LiveStoreEventsTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_EVENTS = [MembersChanged::class, DigestChanged::class, UsageRecorded::class];

    // --- Helpers ---

    private function makeManager(): User
    {
        $manager = User::factory()->create(['tier' => 'team', 'permissions' => 511]);
        $group   = Group::create(['name' => "Team {$manager->id}", 'owner_id' => $manager->id]);
        $group->members()->attach($manager->id);

        License::create([
            'user_id' => $manager->id,
            'lemon_key_hash' => hash('sha256', "manager-{$manager->id}-" . uniqid()),
            'status' => 'active', 'tier' => 'team', 'seats' => 5,
        ]);

        return $manager;
    }

    private function makeMember(Group $group): User
    {
        $member = User::factory()->create(['tier' => 'team', 'permissions' => 127]);
        $group->members()->attach($member->id);

        return $member;
    }

    private function makeSchedule(Group $group): SlackDigestSchedule
    {
        return SlackDigestSchedule::create([
            'group_id' => $group->id, 'day_of_week' => 1, 'deliver_at' => '09:00', 'timezone' => 'UTC',
            'target_type' => 'channel', 'target_id' => 'C001', 'target_label' => '#general', 'active' => true,
        ]);
    }

    private function assertDispatchedTo(string $event, int $groupId): void
    {
        Event::assertDispatched(
            $event,
            fn ($e) => $e->groupId === $groupId && $e->broadcastOn()->name === "private-group.{$groupId}",
        );
    }

    // --- LOCK: publish() contract that already exists ---

    public function test_publish_ignores_an_unknown_event_type(): void
    {
        Event::fake();

        app(SseEventService::class)->publish(1, 'does.not.exist', []);

        Event::assertNothingDispatched();
    }

    public function test_publish_still_dispatches_rule_changed_on_the_group_channel(): void
    {
        Event::fake([RuleChanged::class]);

        app(SseEventService::class)->publish(7, 'rule.changed', []);

        $this->assertDispatchedTo(RuleChanged::class, 7);
    }

    // --- Event map: three new types ---

    public function test_publish_maps_each_new_type_to_its_event_on_the_group_channel(): void
    {
        Event::fake(self::NEW_EVENTS);

        $service = app(SseEventService::class);
        $service->publish(7, 'members.changed', []);
        $service->publish(7, 'digest.changed', []);
        $service->publish(7, 'usage.recorded', []);

        $this->assertDispatchedTo(MembersChanged::class, 7);
        $this->assertDispatchedTo(DigestChanged::class, 7);
        $this->assertDispatchedTo(UsageRecorded::class, 7);
    }

    public function test_new_events_broadcast_under_their_dotted_type_name(): void
    {
        $this->assertSame('members.changed', (new MembersChanged(1, []))->broadcastAs());
        $this->assertSame('digest.changed', (new DigestChanged(1, []))->broadcastAs());
        $this->assertSame('usage.recorded', (new UsageRecorded(1, []))->broadcastAs());
    }

    // --- Members: every mutation tells the group ---

    public function test_inviting_a_member_publishes_members_changed(): void
    {
        $manager = $this->makeManager();
        $invitee = User::factory()->create(['tier' => 'free', 'permissions' => 1]);
        Event::fake(self::NEW_EVENTS);

        $this->actingAs($manager)->post('/console/admin/members', ['email' => $invitee->email])->assertRedirect();

        $this->assertDispatchedTo(MembersChanged::class, $manager->ownedGroup->id);
    }

    public function test_removing_a_member_publishes_members_changed(): void
    {
        $manager = $this->makeManager();
        $member  = $this->makeMember($manager->ownedGroup);
        Event::fake(self::NEW_EVENTS);

        $this->actingAs($manager)->delete("/console/admin/members/{$member->id}")->assertRedirect();

        $this->assertDispatchedTo(MembersChanged::class, $manager->ownedGroup->id);
    }

    public function test_assigning_a_role_publishes_members_changed(): void
    {
        $manager = $this->makeManager();
        $member  = $this->makeMember($manager->ownedGroup);
        Event::fake(self::NEW_EVENTS);

        $this->actingAs($manager)->post("/console/admin/members/{$member->id}/role", ['role' => 'lead'])->assertRedirect();

        $this->assertDispatchedTo(MembersChanged::class, $manager->ownedGroup->id);
    }

    public function test_promoting_a_member_publishes_members_changed(): void
    {
        $manager = $this->makeManager();
        $member  = $this->makeMember($manager->ownedGroup);
        $groupId = $manager->ownedGroup->id;
        Event::fake(self::NEW_EVENTS);

        $this->actingAs($manager)->post("/console/admin/members/{$member->id}/promote")->assertRedirect();

        $this->assertDispatchedTo(MembersChanged::class, $groupId);
    }

    public function test_a_rejected_member_mutation_publishes_nothing(): void
    {
        $manager = $this->makeManager();
        $foreign = $this->makeMember($this->makeManager()->ownedGroup);
        Event::fake(self::NEW_EVENTS);

        $this->actingAs($manager)->delete("/console/admin/members/{$foreign->id}");

        Event::assertNotDispatched(MembersChanged::class);
    }

    // --- Digests: every schedule mutation tells the group ---

    public function test_storing_a_digest_schedule_publishes_digest_changed(): void
    {
        $manager = $this->makeManager();
        Event::fake(self::NEW_EVENTS);

        $this->actingAs($manager)->post('/console/admin/alerts/digest-schedules', [
            'day_of_week' => 1, 'deliver_at' => '09:00', 'timezone' => 'UTC',
            'target_type' => 'channel', 'targets' => [['id' => 'C001', 'label' => '#general']],
        ])->assertRedirect();

        $this->assertDispatchedTo(DigestChanged::class, $manager->ownedGroup->id);
    }

    public function test_toggling_a_digest_schedule_publishes_digest_changed(): void
    {
        $manager  = $this->makeManager();
        $schedule = $this->makeSchedule($manager->ownedGroup);
        Event::fake(self::NEW_EVENTS);

        $this->actingAs($manager)
            ->patch("/console/admin/alerts/digest-schedules/{$schedule->id}", ['active' => false])
            ->assertRedirect();

        $this->assertDispatchedTo(DigestChanged::class, $manager->ownedGroup->id);
    }

    public function test_deleting_a_digest_schedule_publishes_digest_changed(): void
    {
        $manager  = $this->makeManager();
        $schedule = $this->makeSchedule($manager->ownedGroup);
        Event::fake(self::NEW_EVENTS);

        $this->actingAs($manager)
            ->delete("/console/admin/alerts/digest-schedules/{$schedule->id}")
            ->assertRedirect();

        $this->assertDispatchedTo(DigestChanged::class, $manager->ownedGroup->id);
    }

    public function test_a_foreign_groups_digest_schedule_publishes_nothing(): void
    {
        $manager = $this->makeManager();
        $foreign = $this->makeSchedule($this->makeManager()->ownedGroup);
        Event::fake(self::NEW_EVENTS);

        $this->actingAs($manager)->delete("/console/admin/alerts/digest-schedules/{$foreign->id}");

        Event::assertNotDispatched(DigestChanged::class);
    }

    // --- Usage: the single writer tells every group the user belongs to ---

    public function test_recording_ai_usage_publishes_usage_recorded_to_the_users_group(): void
    {
        $manager = $this->makeManager();
        Event::fake(self::NEW_EVENTS);

        UsageLog::recordAiAction($manager, 'summarize', null, 120);

        $this->assertDispatchedTo(UsageRecorded::class, $manager->ownedGroup->id);
    }

    public function test_recording_ai_usage_never_reaches_another_groups_channel(): void
    {
        $manager = $this->makeManager();
        $other   = $this->makeManager();
        Event::fake(self::NEW_EVENTS);

        UsageLog::recordAiAction($manager, 'summarize', null, 120);

        Event::assertNotDispatched(
            UsageRecorded::class,
            fn ($e) => $e->groupId === $other->ownedGroup->id,
        );
    }

    public function test_recording_ai_usage_for_a_user_without_a_group_publishes_nothing(): void
    {
        $solo = User::factory()->create(['tier' => 'free', 'permissions' => 1]);
        Event::fake(self::NEW_EVENTS);

        $row = UsageLog::recordAiAction($solo, 'summarize', null, 120);

        $this->assertNotNull($row->id);
        Event::assertNotDispatched(UsageRecorded::class);
    }

    public function test_recording_ai_usage_publishes_once_per_group_membership(): void
    {
        $manager = $this->makeManager();
        $second  = $this->makeManager();
        $second->ownedGroup->members()->attach($manager->id);
        Event::fake(self::NEW_EVENTS);

        UsageLog::recordAiAction($manager, 'summarize', null, 120);

        Event::assertDispatchedTimes(UsageRecorded::class, 2);
    }

    public function test_usage_row_is_saved_even_when_the_broadcast_driver_is_down(): void
    {
        $manager = $this->makeManager();
        config(['broadcasting.connections.pusher.options.host' => '127.0.0.1', 'broadcasting.connections.pusher.options.port' => 1]);

        $row = UsageLog::recordAiAction($manager, 'summarize', null, 120);

        $this->assertDatabaseHas('usage_logs', ['id' => $row->id, 'tokens_used' => 120]);
    }

    public function test_a_hung_broadcast_server_cannot_stall_the_request(): void
    {
        // Accepts TCP connections (kernel backlog) but never answers: the worst case for a sync broadcast.
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $port   = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'k',
            'broadcasting.connections.reverb.secret' => 's',
            'broadcasting.connections.reverb.app_id' => '1',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => $port,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
        ]);
        app(\Illuminate\Broadcasting\BroadcastManager::class)->purge();

        $start = microtime(true);
        app(SseEventService::class)->publish(1, 'members.changed', []);
        $elapsed = microtime(true) - $start;
        fclose($server);

        $this->assertLessThan(5.0, $elapsed, 'publish() must give up fast; a sync broadcast sits inside the request');
    }

    public function test_every_real_broadcast_connection_sets_a_short_request_timeout(): void
    {
        foreach (['reverb', 'pusher'] as $connection) {
            $timeout = config("broadcasting.connections.{$connection}.options.timeout");

            $this->assertNotNull($timeout, "{$connection} has no client timeout");
            $this->assertLessThanOrEqual(3, $timeout, "{$connection} timeout too long");
        }
    }

    public function test_a_hung_broadcast_server_costs_one_timeout_not_one_per_group(): void
    {
        $manager = $this->makeManager();
        foreach ([$this->makeManager(), $this->makeManager()] as $other) {
            $other->ownedGroup->members()->attach($manager->id);
        }
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $port   = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'k',
            'broadcasting.connections.reverb.secret' => 's',
            'broadcasting.connections.reverb.app_id' => '1',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => $port,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
        ]);
        app(\Illuminate\Broadcasting\BroadcastManager::class)->purge();

        $start = microtime(true);
        $row   = UsageLog::recordAiAction($manager, 'summarize', null, 5);
        $elapsed = microtime(true) - $start;
        fclose($server);

        $this->assertNotNull($row->id);
        $this->assertLessThan(3.5, $elapsed, 'three groups must not triple the stall: give up after the first failure');
    }

    public function test_publish_reports_whether_the_event_was_sent(): void
    {
        Event::fake(self::NEW_EVENTS);
        $service = app(SseEventService::class);

        $this->assertTrue($service->publish(7, 'members.changed', []));
        $this->assertFalse($service->publish(7, 'does.not.exist', []));
    }
}
