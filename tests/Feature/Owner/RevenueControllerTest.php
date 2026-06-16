<?php

namespace Tests\Feature\Owner;

use App\Models\License;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RevenueControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeOwner(): User
    {
        return User::factory()->create(['is_owner' => true]);
    }

    public function test_owner_can_view_revenue(): void
    {
        $owner = $this->makeOwner();

        $response = $this->actingAs($owner)->get('/console/owner/revenue');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Console/Owner/Revenue'));
    }

    public function test_non_owner_cannot_view_revenue(): void
    {
        $user = User::factory()->create(['tier' => 'enterprise', 'permissions' => 1023]);

        $response = $this->actingAs($user)->get('/console/owner/revenue');

        $response->assertRedirect('/console/dashboard');
    }

    public function test_revenue_computes_mrr_from_active_paid_licenses(): void
    {
        $owner = $this->makeOwner();

        $proUser   = User::factory()->create(['tier' => 'pro']);
        $teamUser  = User::factory()->create(['tier' => 'team']);
        $freeUser  = User::factory()->create(['tier' => 'free']);

        License::create(['user_id' => $proUser->id,  'lemon_key_hash' => str_repeat('a', 64), 'status' => 'active', 'tier' => 'pro']);
        License::create(['user_id' => $teamUser->id, 'lemon_key_hash' => str_repeat('b', 64), 'status' => 'active', 'tier' => 'team']);
        License::create(['user_id' => $freeUser->id, 'lemon_key_hash' => str_repeat('c', 64), 'status' => 'active', 'tier' => 'free']);

        $response = $this->actingAs($owner)->get('/console/owner/revenue');

        $response->assertInertia(fn ($page) => $page
            ->component('Console/Owner/Revenue')
            ->where('mrr', 23)   // pro(8) + team(15) + free(0)
            ->where('total_active', 3)
        );
    }

    public function test_revenue_excludes_expired_licenses(): void
    {
        $owner = $this->makeOwner();
        $user  = User::factory()->create(['tier' => 'pro']);

        License::create([
            'user_id'        => $user->id,
            'lemon_key_hash' => str_repeat('d', 64),
            'status'         => 'active',
            'tier'           => 'pro',
            'expires_at'     => now()->subDay(),
        ]);

        $response = $this->actingAs($owner)->get('/console/owner/revenue');

        $response->assertInertia(fn ($page) => $page
            ->where('mrr', 0)
            ->where('total_active', 0)
        );
    }

    // ── LOCK: existing response keys must not change shape ────────────────────

    public function test_lock_existing_revenue_keys_always_present(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)->get('/console/owner/revenue')
            ->assertInertia(fn ($page) => $page
                ->has('mrr')
                ->has('total_active')
                ->has('tier_breakdown')
                ->has('recent_events')
            );
    }

    public function test_lock_tier_breakdown_contains_all_tiers(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)->get('/console/owner/revenue')
            ->assertInertia(fn ($page) => $page
                ->where('tier_breakdown', fn ($tb) => collect($tb)->has(['free', 'pro', 'team', 'enterprise']))
            );
    }

    // ── RED: platform analytics new fields ───────────────────────────────────

    public function test_revenue_includes_signups_per_week(): void
    {
        $owner = $this->makeOwner();
        User::factory()->create(['tier' => 'pro', 'created_at' => now()->subDays(3)]);

        $this->actingAs($owner)->get('/console/owner/revenue')
            ->assertInertia(fn ($page) => $page
                ->has('signups_per_week')
                ->where('signups_per_week', fn ($v) =>
                    count($v) === 8 &&
                    collect($v)->every(fn ($row) => isset($row['week']) && isset($row['count']))
                )
            );
    }

    public function test_revenue_includes_push_volume_per_day(): void
    {
        $owner = $this->makeOwner();
        $user  = User::factory()->create(['tier' => 'pro']);

        \App\Models\TriageSnapshot::create([
            'user_id'      => $user->id,
            'profile'      => 'production',
            'tickets'      => [],
            'ticket_count' => 5,
            'captured_at'  => now()->subDays(2),
        ]);

        $this->actingAs($owner)->get('/console/owner/revenue')
            ->assertInertia(fn ($page) => $page
                ->has('push_volume_per_day')
                ->where('push_volume_per_day', fn ($v) =>
                    collect($v)->every(fn ($row) => isset($row['date']) && isset($row['count']))
                )
            );
    }

    public function test_revenue_includes_dau_wau_mau(): void
    {
        $owner = $this->makeOwner();
        $user  = User::factory()->create(['tier' => 'pro']);

        \App\Models\TriageSnapshot::create([
            'user_id'      => $user->id,
            'profile'      => 'production',
            'tickets'      => [],
            'ticket_count' => 2,
            'captured_at'  => now()->subHours(6),
        ]);

        $this->actingAs($owner)->get('/console/owner/revenue')
            ->assertInertia(fn ($page) => $page
                ->has('dau_wau_mau')
                ->where('dau_wau_mau.dau', 1)
                ->where('dau_wau_mau.wau', 1)
                ->where('dau_wau_mau.mau', 1)
            );
    }

    public function test_revenue_includes_top_teams_by_activity(): void
    {
        $owner   = $this->makeOwner();
        $manager = User::factory()->create(['tier' => 'team', 'permissions' => 511]);
        $group   = \App\Models\Group::create(['name' => 'Test Team', 'owner_id' => $manager->id]);
        $group->members()->attach($manager->id);

        \App\Models\TriageSnapshot::create([
            'user_id'      => $manager->id,
            'profile'      => 'production',
            'tickets'      => [],
            'ticket_count' => 3,
            'captured_at'  => now()->subDays(1),
        ]);

        $this->actingAs($owner)->get('/console/owner/revenue')
            ->assertInertia(fn ($page) => $page
                ->has('top_teams_by_activity')
                ->where('top_teams_by_activity', fn ($v) =>
                    count($v) >= 1 &&
                    collect($v)->every(fn ($row) => isset($row['group_name']) && isset($row['push_count']))
                )
            );
    }
}
