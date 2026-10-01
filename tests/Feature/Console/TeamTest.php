<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get('/console/team');

        $response->assertRedirect('/console/login');
    }

    public function test_user_without_permission_gets_403(): void
    {
        // Pro (71) has no MultiAccount bit (32)
        $user = User::factory()->create(['tier' => 'pro', 'permissions' => 71]);

        $response = $this->actingAs($user)->getJson('/console/team');

        $response->assertStatus(403);
    }

    public function test_team_user_sees_team_page(): void
    {
        // Team = 127 (71|8|16|32): has MultiAccount bit
        $user = User::factory()->create(['tier' => 'team', 'permissions' => 127]);

        $response = $this->actingAs($user)->get('/console/team');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Console/Team')
            ->has('groups')
        );
    }

    // --- last_push must be an unambiguous instant: a bare "Y-m-d H:i:s" is parsed as browser-local
    //     time by JS, which showed "-25199s ago" (UTC data read as PDT, 7h in the future) ---

    private function teamWithOnePush(\Illuminate\Support\Carbon $capturedAt): array
    {
        $user  = User::factory()->create(['tier' => 'team', 'permissions' => 127]);
        $group = \App\Models\Group::create(['name' => "Team {$user->id}", 'owner_id' => $user->id]);
        $group->members()->attach($user->id);
        \App\Models\TriageSnapshot::create([
            'user_id' => $user->id, 'profile' => 'production', 'tickets' => [],
            'ticket_count' => 4, 'captured_at' => $capturedAt,
        ]);

        return [$user, $capturedAt];
    }

    public function test_last_push_is_an_iso8601_instant_with_an_explicit_offset(): void
    {
        [$user] = $this->teamWithOnePush(now()->subMinutes(5));

        $this->actingAs($user)->get('/console/team')->assertInertia(fn ($page) => $page
            ->where('groups.0.members.0.last_push', fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $v) === 1)
        );
    }

    public function test_last_push_is_the_same_instant_that_was_stored(): void
    {
        [$user, $capturedAt] = $this->teamWithOnePush(now()->subHours(3)->startOfSecond());

        $this->actingAs($user)->get('/console/team')->assertInertia(fn ($page) => $page
            ->where('groups.0.members.0.last_push', fn ($v) => \Illuminate\Support\Carbon::parse($v)->equalTo($capturedAt))
        );
    }

    public function test_last_push_is_null_for_a_member_who_never_pushed(): void
    {
        $user  = User::factory()->create(['tier' => 'team', 'permissions' => 127]);
        $group = \App\Models\Group::create(['name' => "Team {$user->id}", 'owner_id' => $user->id]);
        $group->members()->attach($user->id);

        $this->actingAs($user)->get('/console/team')->assertInertia(fn ($page) => $page
            ->where('groups.0.members.0.last_push', null)
            ->where('groups.0.members.0.ticket_count', 0)
        );
    }

    public function test_last_push_reports_the_newest_snapshot(): void
    {
        [$user] = $this->teamWithOnePush(now()->subDays(2)->startOfSecond());
        $newest = now()->subMinutes(1)->startOfSecond();
        \App\Models\TriageSnapshot::create([
            'user_id' => $user->id, 'profile' => 'staging', 'tickets' => [], 'ticket_count' => 1, 'captured_at' => $newest,
        ]);

        $this->actingAs($user)->get('/console/team')->assertInertia(fn ($page) => $page
            ->where('groups.0.members.0.last_push', fn ($v) => \Illuminate\Support\Carbon::parse($v)->equalTo($newest))
        );
    }
}
