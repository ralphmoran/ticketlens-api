<?php

namespace Tests\Feature\Console;

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class BroadcastChannelTest extends TestCase
{
    use RefreshDatabase;

    // ─── Structural: files and classes that must exist after 4-green ──────

    public function test_channels_route_file_exists(): void
    {
        $this->assertTrue(file_exists(base_path('routes/channels.php')));
    }

    public function test_rule_changed_event_class_exists(): void
    {
        $this->assertTrue(class_exists(\App\Events\RuleChanged::class));
    }

    public function test_triage_pushed_event_class_exists(): void
    {
        $this->assertTrue(class_exists(\App\Events\TriagePushed::class));
    }

    // ─── Behavioural: SseEventService must dispatch broadcast events ───────

    public function test_publish_dispatches_rule_changed_broadcast_event(): void
    {
        $this->assertTrue(class_exists(\App\Events\RuleChanged::class));

        Event::fake([\App\Events\RuleChanged::class]);
        app(\App\Services\SseEventService::class)->publish(1, 'rule.changed', ['key' => 'val']);
        Event::assertDispatched(\App\Events\RuleChanged::class);
    }

    public function test_publish_dispatches_triage_pushed_broadcast_event(): void
    {
        $this->assertTrue(class_exists(\App\Events\TriagePushed::class));

        Event::fake([\App\Events\TriagePushed::class]);
        app(\App\Services\SseEventService::class)->publish(1, 'triage.pushed', ['key' => 'val']);
        Event::assertDispatched(\App\Events\TriagePushed::class);
    }

    // ─── Inertia shared props ──────────────────────────────────────────────

    public function test_inertia_shared_props_include_group_id_for_team_member(): void
    {
        $user  = User::factory()->create(['tier' => 'team', 'permissions' => 511]);
        $group = Group::create(['name' => "Team {$user->id}", 'owner_id' => $user->id]);
        $group->members()->attach($user->id);

        $this->actingAs($user)
            ->get('/console/dashboard')
            ->assertInertia(fn ($page) => $page->has('auth.group_id'));
    }

    // ─── Channel authorization via /broadcasting/auth ─────────────────────
    // All fail RED: /broadcasting/auth route is 404 until Broadcast::routes()
    // is registered in web.php and routes/channels.php exists. After 4-green,
    // phpunit.xml also needs BROADCAST_CONNECTION=pusher with test credentials.

    public function test_unauthenticated_user_cannot_get_channel_auth_token(): void
    {
        // Laravel's broadcasting/auth endpoint returns 403 (not 401) for guests
        // because the web middleware stack resolves unauthenticated JSON requests
        // to forbidden before the auth middleware can issue a redirect challenge.
        $this->postJson('/broadcasting/auth', [
                'channel_name' => 'private-group.1',
                'socket_id'    => '123456.789012',
            ])
            ->assertForbidden();
    }

    public function test_unverified_user_cannot_get_channel_auth_token(): void
    {
        $user = User::factory()->create([
            'tier'                => 'team',
            'permissions'         => 511,
            'email_verified_at'   => null,
        ]);

        $this->actingAs($user)
            ->postJson('/broadcasting/auth', [
                'channel_name' => 'private-group.1',
                'socket_id'    => '123456.789012',
            ])
            ->assertForbidden();
    }

    public function test_free_user_is_blocked_from_group_channel(): void
    {
        $user = User::factory()->create(['tier' => 'free', 'permissions' => 0]);

        $this->actingAs($user)
            ->postJson('/broadcasting/auth', [
                'channel_name' => 'private-group.1',
                'socket_id'    => '123456.789012',
            ])
            ->assertForbidden();
    }

    public function test_pro_member_can_subscribe_to_own_group_channel(): void
    {
        $user  = User::factory()->create(['tier' => 'pro', 'permissions' => 511]);
        $group = Group::create(['name' => "Pro {$user->id}", 'owner_id' => $user->id]);
        $group->members()->attach($user->id);

        $this->actingAs($user)
            ->postJson('/broadcasting/auth', [
                'channel_name' => "private-group.{$group->id}",
                'socket_id'    => '123456.789012',
            ])
            ->assertOk();
    }

    public function test_team_member_can_subscribe_to_own_group_channel(): void
    {
        $user  = User::factory()->create(['tier' => 'team', 'permissions' => 511]);
        $group = Group::create(['name' => "Team {$user->id}", 'owner_id' => $user->id]);
        $group->members()->attach($user->id);

        $this->actingAs($user)
            ->postJson('/broadcasting/auth', [
                'channel_name' => "private-group.{$group->id}",
                'socket_id'    => '123456.789012',
            ])
            ->assertOk();
    }

    public function test_team_member_cannot_subscribe_to_another_groups_channel(): void
    {
        $user  = User::factory()->create(['tier' => 'team', 'permissions' => 511]);
        $group = Group::create(['name' => "Team {$user->id}", 'owner_id' => $user->id]);
        $group->members()->attach($user->id);

        $otherGroup = Group::create(['name' => 'Other', 'owner_id' => $user->id]);

        $this->actingAs($user)
            ->postJson('/broadcasting/auth', [
                'channel_name' => "private-group.{$otherGroup->id}",
                'socket_id'    => '123456.789012',
            ])
            ->assertForbidden();
    }

    public function test_owner_can_subscribe_to_any_group_channel_for_impersonation(): void
    {
        $owner = User::factory()->create([
            'tier'        => 'owner',
            'is_owner'    => true,
            'permissions' => 0,
        ]);
        $group = Group::create(['name' => 'A Team', 'owner_id' => $owner->id]);

        $this->actingAs($owner)
            ->postJson('/broadcasting/auth', [
                'channel_name' => "private-group.{$group->id}",
                'socket_id'    => '123456.789012',
            ])
            ->assertOk();
    }
}
