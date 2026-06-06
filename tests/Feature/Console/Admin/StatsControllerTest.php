<?php

namespace Tests\Feature\Console\Admin;

use App\Models\Group;
use App\Models\TriageSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatsControllerTest extends TestCase
{
    use RefreshDatabase;

    // --- Helpers ---

    private function makeManager(string $name = null): User
    {
        $manager = User::factory()->create([
            'tier'        => 'team',
            'permissions' => 511,
            'name'        => $name ?? fake()->name(),
        ]);
        $group = Group::create(['name' => "Team {$manager->id}", 'owner_id' => $manager->id]);
        $group->members()->attach($manager->id);
        return $manager;
    }

    private function makeMember(Group $group, string $name = null): User
    {
        $member = User::factory()->create([
            'tier'        => 'team',
            'permissions' => 127,
            'name'        => $name ?? fake()->name(),
        ]);
        $group->members()->attach($member->id);
        return $member;
    }

    private function pushSnapshot(User $user, array $tickets, string $profile = 'production', string $capturedAt = null): TriageSnapshot
    {
        return TriageSnapshot::create([
            'user_id'      => $user->id,
            'profile'      => $profile,
            'tickets'      => $tickets,
            'ticket_count' => count($tickets),
            'captured_at'  => $capturedAt ? now()->parse($capturedAt) : now(),
        ]);
    }

    private function ticket(string $key, array $flags = [], string $lastCommentAt = null): array
    {
        return [
            'key'             => $key,
            'summary'         => "Summary for {$key}",
            'status'          => 'In Progress',
            'assignee'        => 'Dev',
            'attention_score' => 5.0,
            'flags'           => $flags,
            'last_comment_at' => $lastCommentAt,
            'compliance_coverage' => null,
            'compliance_status'   => 'unknown',
            'url'             => "https://jira.example.com/browse/{$key}",
            'last_updated'    => '2026-05-12T09:00:00Z',
        ];
    }

    // --- Access control ---

    public function test_guest_redirected_to_login(): void
    {
        $this->get('/console/admin/stats')->assertRedirect('/console/login');
    }

    public function test_team_member_without_manager_bit_redirected(): void
    {
        $user = User::factory()->create(['tier' => 'team', 'permissions' => 127]);

        $this->actingAs($user)->get('/console/admin/stats')
            ->assertRedirect('/console/dashboard');
    }

    public function test_manager_with_no_group_gets_403(): void
    {
        $user = User::factory()->create(['tier' => 'team', 'permissions' => 511]);

        $this->actingAs($user)->get('/console/admin/stats')
            ->assertStatus(403);
    }

    public function test_manager_can_access_stats_page(): void
    {
        $manager = $this->makeManager();

        $this->actingAs($manager)->get('/console/admin/stats')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page->component('Console/Admin/Stats'));
    }

    // --- Empty state ---

    public function test_empty_state_when_no_pushes(): void
    {
        $manager = $this->makeManager();
        $this->makeMember($manager->ownedGroup);

        $this->actingAs($manager)->get('/console/admin/stats')
            ->assertInertia(fn ($page) => $page
                ->where('daily_urgency', [])
                ->where('team_comparison', fn ($rows) => collect($rows)->every(fn ($r) => $r['needs_response'] === 0))
                ->where('last_updated', null)
            );
    }

    // --- Urgency snapshot (latest per member) ---

    public function test_team_comparison_shows_current_urgency_counts(): void
    {
        $manager = $this->makeManager();
        $dev     = $this->makeMember($manager->ownedGroup, 'Alice');

        $this->pushSnapshot($dev, [
            $this->ticket('A-1', ['needs-response']),
            $this->ticket('A-2', ['needs-response']),
            $this->ticket('A-3', ['aging']),
            $this->ticket('A-4'),
        ]);

        $this->actingAs($manager)->get('/console/admin/stats')
            ->assertInertia(fn ($page) => $page
                ->has('team_comparison', 2) // manager + dev
                ->where('team_comparison', fn ($rows) =>
                    collect($rows)->contains(fn ($r) =>
                        $r['member_name'] === 'Alice' &&
                        $r['needs_response'] === 2 &&
                        $r['aging'] === 1 &&
                        $r['clear'] === 1 &&
                        $r['total'] === 4
                    )
                )
            );
    }

    public function test_team_comparison_uses_latest_snapshot_per_profile(): void
    {
        $manager = $this->makeManager();
        $dev     = $this->makeMember($manager->ownedGroup, 'Bob');

        // Old snapshot — 5 needs-response (must NOT appear)
        TriageSnapshot::create([
            'user_id'      => $dev->id,
            'profile'      => 'production',
            'tickets'      => array_map(fn ($i) => $this->ticket("OLD-$i", ['needs-response']), range(1, 5)),
            'ticket_count' => 5,
            'captured_at'  => now()->subDays(3),
        ]);
        // Latest snapshot — 1 needs-response
        $this->pushSnapshot($dev, [$this->ticket('NEW-1', ['needs-response']), $this->ticket('NEW-2')]);

        $this->actingAs($manager)->get('/console/admin/stats')
            ->assertInertia(fn ($page) => $page
                ->where('team_comparison', fn ($rows) =>
                    collect($rows)->contains(fn ($r) =>
                        $r['member_name'] === 'Bob' &&
                        $r['needs_response'] === 1 &&
                        $r['total'] === 2
                    )
                )
            );
    }

    // --- Daily urgency trend ---

    public function test_daily_urgency_built_from_historical_snapshots(): void
    {
        $manager = $this->makeManager();
        $dev     = $this->makeMember($manager->ownedGroup);

        // Two different days
        $this->pushSnapshot($dev, [
            $this->ticket('D1-1', ['needs-response']),
            $this->ticket('D1-2'),
        ], 'production', '2026-05-28T10:00:00Z');

        $this->pushSnapshot($dev, [
            $this->ticket('D2-1'),
            $this->ticket('D2-2'),
            $this->ticket('D2-3'),
        ], 'production', '2026-05-29T10:00:00Z');

        $this->actingAs($manager)->get('/console/admin/stats')
            ->assertInertia(fn ($page) => $page
                ->has('daily_urgency')
                ->where('daily_urgency.0.date', '2026-05-28')
                ->where('daily_urgency.0.needs_response', 1)
                ->where('daily_urgency.0.clear', 1)
                ->where('daily_urgency.1.date', '2026-05-29')
                ->where('daily_urgency.1.clear', 3)
            );
    }

    public function test_daily_urgency_limited_to_30_days(): void
    {
        $manager = $this->makeManager();
        $dev     = $this->makeMember($manager->ownedGroup);

        // Old snapshot (31 days ago) — must not appear
        TriageSnapshot::create([
            'user_id'      => $dev->id,
            'profile'      => 'production',
            'tickets'      => [$this->ticket('OLD-1', ['needs-response'])],
            'ticket_count' => 1,
            'captured_at'  => now()->subDays(31),
        ]);

        // Recent snapshot (5 days ago) — must appear
        $this->pushSnapshot($dev, [$this->ticket('NEW-1')]);

        $this->actingAs($manager)->get('/console/admin/stats')
            ->assertInertia(fn ($page) => $page
                ->has('daily_urgency', 1)
                ->where('daily_urgency.0.needs_response', 0)
                ->where('daily_urgency.0.clear', 1)
            );
    }

    public function test_daily_urgency_deduplicates_multiple_pushes_per_day(): void
    {
        $manager = $this->makeManager();
        $dev     = $this->makeMember($manager->ownedGroup);

        // Two snapshots on the same day (morning and evening) — only latest counts
        TriageSnapshot::create([
            'user_id'      => $dev->id,
            'profile'      => 'production',
            'tickets'      => array_map(fn ($i) => $this->ticket("AM-$i", ['needs-response']), range(1, 3)),
            'ticket_count' => 3,
            'captured_at'  => now()->setTime(8, 0, 0),
        ]);
        TriageSnapshot::create([
            'user_id'      => $dev->id,
            'profile'      => 'production',
            'tickets'      => [$this->ticket('PM-1')],
            'ticket_count' => 1,
            'captured_at'  => now()->setTime(18, 0, 0),
        ]);

        $this->actingAs($manager)->get('/console/admin/stats')
            ->assertInertia(fn ($page) => $page
                ->has('daily_urgency', 1)
                ->where('daily_urgency.0.needs_response', 0)
                ->where('daily_urgency.0.clear', 1)
            );
    }

    // --- Tenant isolation ---

    public function test_stats_only_shows_own_group_data(): void
    {
        $manager      = $this->makeManager();
        $otherManager = $this->makeManager();
        $otherMember  = $this->makeMember($otherManager->ownedGroup);

        $this->pushSnapshot($otherMember, [$this->ticket('X-1', ['needs-response'])]);

        $this->actingAs($manager)->get('/console/admin/stats')
            ->assertInertia(fn ($page) => $page
                ->where('daily_urgency', [])
            );
    }

    // --- Owner mode ---

    public function test_owner_without_manager_id_sees_client_list(): void
    {
        $owner   = User::factory()->create(['is_owner' => true, 'permissions' => 0]);
        $manager = $this->makeManager('Eve');

        $this->actingAs($owner)->get('/console/admin/stats')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Console/Admin/Stats')
                ->where('owner_mode', true)
                ->where('selected_manager', null)
                ->has('clients', 1)
                ->where('clients.0.name', 'Eve')
            );
    }

    public function test_owner_with_manager_id_sees_that_teams_stats(): void
    {
        $owner   = User::factory()->create(['is_owner' => true, 'permissions' => 0]);
        $manager = $this->makeManager('Frank');
        $dev     = $this->makeMember($manager->ownedGroup, 'Grace');
        $this->pushSnapshot($dev, [$this->ticket('F-1', ['needs-response'])]);

        $this->actingAs($owner)->get("/console/admin/stats?manager_id={$manager->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('owner_mode', true)
                ->where('selected_manager.name', 'Frank')
                ->has('team_comparison', 2)
            );
    }

    public function test_owner_with_invalid_manager_id_redirects(): void
    {
        $owner = User::factory()->create(['is_owner' => true, 'permissions' => 0]);

        $this->actingAs($owner)->get('/console/admin/stats?manager_id=99999')
            ->assertRedirect('/console/admin/stats');
    }

    // ── Stale band ────────────────────────────────────────────────────────────

    public function test_daily_urgency_includes_stale_band(): void
    {
        $manager = $this->makeManager();
        $dev     = $this->makeMember($manager->ownedGroup, 'Dave');
        $this->pushSnapshot($dev, [
            $this->ticket('S-1', ['stale']),
            $this->ticket('S-2', ['aging']),
            $this->ticket('S-3'),
        ]);

        $this->actingAs($manager)->get('/console/admin/stats')
            ->assertInertia(fn ($page) => $page
                ->where('daily_urgency.0.stale', 1)
                ->where('daily_urgency.0.aging', 1)
                ->where('daily_urgency.0.clear', 1)
            );
    }

    public function test_team_comparison_includes_stale_band(): void
    {
        $manager = $this->makeManager();
        $dev     = $this->makeMember($manager->ownedGroup, 'Eve');
        $this->pushSnapshot($dev, [
            $this->ticket('S-4', ['stale']),
            $this->ticket('S-5', ['stale']),
        ]);

        $this->actingAs($manager)->get('/console/admin/stats')
            ->assertInertia(fn ($page) => $page
                ->where('team_comparison', fn ($rows) =>
                    collect($rows)->contains(fn ($r) =>
                        $r['member_name'] === 'Eve' && $r['stale'] === 2
                    )
                )
            );
    }

    // ── RED: new stats fields ─────────────────────────────────────────────────

    public function test_push_heatmap_present_in_response(): void
    {
        $manager = $this->makeManager();
        $dev     = $this->makeMember($manager->ownedGroup);
        $this->pushSnapshot($dev, [$this->ticket('H-1')], 'production', now()->subDays(5)->toIso8601String());

        $this->actingAs($manager)->get('/console/admin/stats')
            ->assertInertia(fn ($page) => $page
                ->has('push_heatmap')
                ->where('push_heatmap', fn ($v) =>
                    collect($v)->contains(fn ($row) => isset($row['member_id']) && isset($row['days']))
                )
            );
    }

    public function test_hour_distribution_present_and_has_24_buckets(): void
    {
        $manager = $this->makeManager();
        $dev     = $this->makeMember($manager->ownedGroup);
        $this->pushSnapshot($dev, [$this->ticket('HR-1')]);

        $this->actingAs($manager)->get('/console/admin/stats')
            ->assertInertia(fn ($page) => $page
                ->has('hour_distribution')
                ->where('hour_distribution', fn ($v) => count($v) === 24)
            );
    }

    public function test_day_of_week_distribution_present_and_has_7_buckets(): void
    {
        $manager = $this->makeManager();
        $dev     = $this->makeMember($manager->ownedGroup);
        $this->pushSnapshot($dev, [$this->ticket('DW-1')]);

        $this->actingAs($manager)->get('/console/admin/stats')
            ->assertInertia(fn ($page) => $page
                ->has('day_of_week_dist')
                ->where('day_of_week_dist', fn ($v) => count($v) === 7)
            );
    }

    public function test_engagement_scores_present_with_required_keys(): void
    {
        $manager = $this->makeManager();
        $dev     = $this->makeMember($manager->ownedGroup, 'Engaged Dev');
        $this->pushSnapshot($dev, [$this->ticket('E-1')]);

        $this->actingAs($manager)->get('/console/admin/stats')
            ->assertInertia(fn ($page) => $page
                ->has('engagement_scores')
                ->where('engagement_scores', fn ($v) =>
                    collect($v)->contains(fn ($row) =>
                        isset($row['member_name']) &&
                        isset($row['active_days_30d']) &&
                        isset($row['avg_ticket_count']) &&
                        isset($row['score'])
                    )
                )
            );
    }

    public function test_ticket_load_trend_present_per_member(): void
    {
        $manager = $this->makeManager();
        $dev     = $this->makeMember($manager->ownedGroup, 'Trend Dev');
        $this->pushSnapshot($dev, [$this->ticket('T-1'), $this->ticket('T-2')], 'production', now()->subDays(3)->toIso8601String());

        $this->actingAs($manager)->get('/console/admin/stats')
            ->assertInertia(fn ($page) => $page
                ->has('ticket_load_trend')
                ->where('ticket_load_trend', fn ($v) =>
                    collect($v)->contains(fn ($row) =>
                        isset($row['member_name']) && isset($row['data'])
                    )
                )
            );
    }

    // ── LOCK: existing response keys must not change shape ────────────────────

    public function test_lock_existing_response_keys_are_present(): void
    {
        $manager = $this->makeManager();
        $dev     = $this->makeMember($manager->ownedGroup);
        $this->pushSnapshot($dev, [$this->ticket('L-1')]);

        $this->actingAs($manager)->get('/console/admin/stats')
            ->assertInertia(fn ($page) => $page
                ->has('daily_urgency')
                ->has('team_comparison')
                ->has('group_name')
                ->has('last_updated')
                ->has('daily_urgency', 1)
                ->has('team_comparison', 2)
            );
    }

    public function test_lock_team_comparison_row_keys_unchanged(): void
    {
        $manager = $this->makeManager();
        $dev     = $this->makeMember($manager->ownedGroup, 'Lock Dev');
        $this->pushSnapshot($dev, [$this->ticket('L-2', ['needs-response'])]);

        $this->actingAs($manager)->get('/console/admin/stats')
            ->assertInertia(fn ($page) => $page
                ->where('team_comparison', fn ($rows) =>
                    collect($rows)->every(fn ($r) =>
                        array_key_exists('member_id', $r) &&
                        array_key_exists('member_name', $r) &&
                        array_key_exists('needs_response', $r) &&
                        array_key_exists('aging', $r) &&
                        array_key_exists('stale', $r) &&
                        array_key_exists('clear', $r) &&
                        array_key_exists('total', $r) &&
                        array_key_exists('last_push', $r)
                    )
                )
            );
    }
}
