<?php

namespace Tests\Feature\Console\Admin;

use App\Models\Group;
use App\Models\TriageSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComplianceAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    // --- Helpers ---

    private function makeManager(string $name = 'Manager'): User
    {
        $manager = User::factory()->create([
            'tier'        => 'team',
            'permissions' => 511,
            'name'        => $name,
        ]);
        $group = Group::create(['name' => "Team {$manager->id}", 'owner_id' => $manager->id]);
        $group->members()->attach($manager->id);
        return $manager;
    }

    private function makeMember(Group $group, string $name = 'Member'): User
    {
        $member = User::factory()->create([
            'tier'        => 'team',
            'permissions' => 127,
            'name'        => $name,
        ]);
        $group->members()->attach($member->id);
        return $member;
    }

    private int $snapshotSeq = 0;

    private function pushSnapshot(User $user, array $tickets, string $capturedAt = null): TriageSnapshot
    {
        $this->snapshotSeq++;
        return TriageSnapshot::create([
            'user_id'          => $user->id,
            'license_key_hash' => hash('sha256', "key-{$user->id}-{$this->snapshotSeq}"),
            'profile'          => 'production',
            'tickets'          => $tickets,
            'ticket_count'     => count($tickets),
            'captured_at'      => $capturedAt ?? now()->toIso8601String(),
        ]);
    }

    private function ticket(
        string $key,
        string $complianceStatus = 'unknown',
        ?float $complianceCoverage = null,
        string $status = 'In Progress',
    ): array {
        return [
            'key'                 => $key,
            'summary'             => "Summary of {$key}",
            'status'              => $status,
            'assignee'            => null,
            'flags'               => [],
            'attention_score'     => null,
            'compliance_coverage' => $complianceCoverage,
            'compliance_status'   => $complianceStatus,
            'url'                 => "https://jira.example.com/browse/{$key}",
            'last_updated'        => now()->toIso8601String(),
        ];
    }

    // --- LOCK: permission gate ---

    public function test_unauthenticated_redirects_to_login(): void
    {
        $this->get('/console/admin/compliance-analytics')
             ->assertRedirect('/console/login');
    }

    public function test_free_tier_user_cannot_access(): void
    {
        $user = User::factory()->create(['tier' => 'free', 'permissions' => 0]);
        $this->actingAs($user)->get('/console/admin/compliance-analytics')
             ->assertRedirect('/console/dashboard');
    }

    public function test_pro_user_cannot_access(): void
    {
        $user = User::factory()->create(['tier' => 'pro', 'permissions' => 1]);
        $this->actingAs($user)->get('/console/admin/compliance-analytics')
             ->assertRedirect('/console/dashboard');
    }

    // --- Access control ---

    public function test_team_lead_can_access(): void
    {
        $manager = $this->makeManager();
        $lead    = $this->makeMember($manager->ownedGroup);
        $lead->permissions = 127 | 1024;
        $lead->save();
        $this->actingAs($lead)->get('/console/admin/compliance-analytics')
             ->assertOk();
    }

    public function test_team_manager_can_access(): void
    {
        $manager = $this->makeManager();
        $this->actingAs($manager)->get('/console/admin/compliance-analytics')
             ->assertOk();
    }

    public function test_owner_can_access(): void
    {
        $owner = User::factory()->create(['is_owner' => true, 'permissions' => 0]);
        $this->actingAs($owner)->get('/console/admin/compliance-analytics')
             ->assertOk();
    }

    // --- Empty state ---

    public function test_empty_team_returns_zeroed_data(): void
    {
        $manager = $this->makeManager();
        $this->actingAs($manager)
             ->get('/console/admin/compliance-analytics')
             ->assertOk()
             ->assertInertia(fn ($page) => $page
                 ->component('Console/Admin/ComplianceAnalytics')
                 ->where('gap_by_prefix', [])
                 ->where('gap_by_status', [])
                 ->where('weekly_trend', [])
                 ->where('total_checked', 0)
                 ->where('overall_gap_rate', null)
             );
    }

    public function test_all_unknown_compliance_shows_empty_aggregates(): void
    {
        $manager = $this->makeManager();
        $member  = $this->makeMember($manager->ownedGroup);
        $this->pushSnapshot($member, [
            $this->ticket('PROJ-1', 'unknown'),
            $this->ticket('PROJ-2', 'unknown'),
        ]);

        $this->actingAs($manager)
             ->get('/console/admin/compliance-analytics')
             ->assertOk()
             ->assertInertia(fn ($page) => $page
                 ->where('gap_by_prefix', [])
                 ->where('total_checked', 0)
             );
    }

    // --- Gap-by-prefix aggregation ---

    public function test_gap_by_prefix_groups_by_project_prefix(): void
    {
        $manager = $this->makeManager();
        $member  = $this->makeMember($manager->ownedGroup);
        $this->pushSnapshot($member, [
            $this->ticket('PROJ-1', 'gap'),
            $this->ticket('PROJ-2', 'pass'),
            $this->ticket('PROJ-3', 'gap'),
            $this->ticket('ALPHA-1', 'pass'),
        ]);

        $this->actingAs($manager)
             ->get('/console/admin/compliance-analytics')
             ->assertOk()
             ->assertInertia(function ($page) {
                 $gapByPrefix = collect($page->toArray()['props']['gap_by_prefix'])->keyBy('prefix');

                 $this->assertEquals(3, $gapByPrefix['PROJ']['total']);
                 $this->assertEquals(2, $gapByPrefix['PROJ']['gap']);
                 $this->assertEquals(1, $gapByPrefix['PROJ']['pass']);
                 $this->assertEquals(66.7, $gapByPrefix['PROJ']['gap_rate']);
                 $this->assertEquals(0, $gapByPrefix['ALPHA']['gap']);
                 $this->assertEquals(0.0, $gapByPrefix['ALPHA']['gap_rate']);
             });
    }

    public function test_gap_by_prefix_sorted_by_gap_rate_descending(): void
    {
        $manager = $this->makeManager();
        $member  = $this->makeMember($manager->ownedGroup);
        $this->pushSnapshot($member, [
            $this->ticket('ALPHA-1', 'pass'),
            $this->ticket('ALPHA-2', 'pass'),
            $this->ticket('PROJ-1', 'gap'),
            $this->ticket('PROJ-2', 'gap'),
            $this->ticket('PROJ-3', 'pass'),
        ]);

        $this->actingAs($manager)
             ->get('/console/admin/compliance-analytics')
             ->assertOk()
             ->assertInertia(function ($page) {
                 $prefixes = collect($page->toArray()['props']['gap_by_prefix'])->pluck('prefix')->all();
                 $this->assertEquals(['PROJ', 'ALPHA'], $prefixes);
             });
    }

    // --- Gap-by-status aggregation ---

    public function test_gap_by_status_groups_by_ticket_status(): void
    {
        $manager = $this->makeManager();
        $member  = $this->makeMember($manager->ownedGroup);
        $this->pushSnapshot($member, [
            $this->ticket('PROJ-1', 'gap', null, 'In Review'),
            $this->ticket('PROJ-2', 'gap', null, 'In Review'),
            $this->ticket('PROJ-3', 'pass', null, 'Done'),
        ]);

        $this->actingAs($manager)
             ->get('/console/admin/compliance-analytics')
             ->assertOk()
             ->assertInertia(function ($page) {
                 $byStatus = collect($page->toArray()['props']['gap_by_status'])->keyBy('status');
                 $this->assertEquals(100.0, $byStatus['In Review']['gap_rate']);
                 $this->assertEquals(0.0, $byStatus['Done']['gap_rate']);
             });
    }

    // --- Weekly trend ---

    public function test_weekly_trend_groups_by_week(): void
    {
        $manager = $this->makeManager();
        $member  = $this->makeMember($manager->ownedGroup);

        $week1 = now()->subWeeks(2)->startOfWeek()->addDays(1)->toIso8601String();
        $week2 = now()->subWeeks(1)->startOfWeek()->addDays(1)->toIso8601String();

        $this->pushSnapshot($member, [$this->ticket('PROJ-1', 'gap')], $week1);
        $this->pushSnapshot($member, [$this->ticket('PROJ-2', 'pass')], $week2);

        $this->actingAs($manager)
             ->get('/console/admin/compliance-analytics')
             ->assertOk()
             ->assertInertia(function ($page) {
                 $trend = $page->toArray()['props']['weekly_trend'];
                 $this->assertCount(2, $trend);
                 $this->assertEquals(100.0, $trend[0]['gap_rate']);
                 $this->assertEquals(0.0, $trend[1]['gap_rate']);
             });
    }

    // --- Overall stats ---

    public function test_total_checked_counts_only_known_statuses(): void
    {
        $manager = $this->makeManager();
        $member  = $this->makeMember($manager->ownedGroup);
        $this->pushSnapshot($member, [
            $this->ticket('PROJ-1', 'pass'),
            $this->ticket('PROJ-2', 'gap'),
            $this->ticket('PROJ-3', 'unknown'),
        ]);

        $this->actingAs($manager)
             ->get('/console/admin/compliance-analytics')
             ->assertOk()
             ->assertInertia(fn ($page) => $page->where('total_checked', 2));
    }

    public function test_overall_gap_rate_calculation(): void
    {
        $manager = $this->makeManager();
        $member  = $this->makeMember($manager->ownedGroup);
        $this->pushSnapshot($member, [
            $this->ticket('PROJ-1', 'gap'),
            $this->ticket('PROJ-2', 'gap'),
            $this->ticket('PROJ-3', 'pass'),
            $this->ticket('PROJ-4', 'pass'),
        ]);

        $this->actingAs($manager)
             ->get('/console/admin/compliance-analytics')
             ->assertOk()
             ->assertInertia(fn ($page) => $page->where('overall_gap_rate', 50));
    }

    public function test_avg_coverage_from_compliance_coverage_field(): void
    {
        $manager = $this->makeManager();
        $member  = $this->makeMember($manager->ownedGroup);
        $this->pushSnapshot($member, [
            $this->ticket('PROJ-1', 'pass', 80.0),
            $this->ticket('PROJ-2', 'gap',  60.0),
        ]);

        $this->actingAs($manager)
             ->get('/console/admin/compliance-analytics')
             ->assertOk()
             ->assertInertia(fn ($page) => $page->where('avg_coverage', 70));
    }

    // --- Multi-member aggregation ---

    public function test_aggregates_across_all_team_members(): void
    {
        $manager = $this->makeManager();
        $alice   = $this->makeMember($manager->ownedGroup, 'Alice');
        $bob     = $this->makeMember($manager->ownedGroup, 'Bob');

        $this->pushSnapshot($alice, [$this->ticket('PROJ-1', 'gap')]);
        $this->pushSnapshot($bob,   [$this->ticket('PROJ-2', 'pass')]);

        $this->actingAs($manager)
             ->get('/console/admin/compliance-analytics')
             ->assertOk()
             ->assertInertia(fn ($page) => $page
                 ->where('total_checked', 2)
                 ->where('overall_gap_rate', 50)
             );
    }

    // --- Owner mode ---

    public function test_owner_sees_picker_without_manager_id(): void
    {
        $owner   = User::factory()->create(['is_owner' => true, 'permissions' => 0]);
        $this->makeManager('Alice');

        $this->actingAs($owner)
             ->get('/console/admin/compliance-analytics')
             ->assertOk()
             ->assertInertia(fn ($page) => $page
                 ->where('owner_mode', true)
                 ->where('selected_manager', null)
                 ->has('clients', 1)
                 ->where('clients.0.name', 'Alice')
             );
    }

    public function test_owner_with_manager_id_sees_that_teams_data(): void
    {
        $owner   = User::factory()->create(['is_owner' => true, 'permissions' => 0]);
        $manager = $this->makeManager('Alice');
        $member  = $this->makeMember($manager->ownedGroup);
        $this->pushSnapshot($member, [$this->ticket('PROJ-1', 'gap')]);

        $this->actingAs($owner)
             ->get("/console/admin/compliance-analytics?manager_id={$manager->id}")
             ->assertOk()
             ->assertInertia(fn ($page) => $page
                 ->where('owner_mode', true)
                 ->where('selected_manager.id', $manager->id)
                 ->where('total_checked', 1)
             );
    }

    // --- Snapshot age filter ---

    public function test_snapshots_older_than_90_days_are_excluded(): void
    {
        $manager = $this->makeManager();
        $member  = $this->makeMember($manager->ownedGroup);

        $this->pushSnapshot($member, [$this->ticket('PROJ-1', 'gap')], now()->subDays(91)->toIso8601String());

        $this->actingAs($manager)
             ->get('/console/admin/compliance-analytics')
             ->assertOk()
             ->assertInertia(fn ($page) => $page->where('total_checked', 0));
    }

    public function test_snapshots_within_90_days_are_included(): void
    {
        $manager = $this->makeManager();
        $member  = $this->makeMember($manager->ownedGroup);

        $this->pushSnapshot($member, [$this->ticket('PROJ-1', 'pass')], now()->subDays(89)->toIso8601String());

        $this->actingAs($manager)
             ->get('/console/admin/compliance-analytics')
             ->assertOk()
             ->assertInertia(fn ($page) => $page->where('total_checked', 1));
    }

    public function test_deduplicates_to_latest_snapshot_per_user_per_day(): void
    {
        $manager = $this->makeManager();
        $member  = $this->makeMember($manager->ownedGroup);
        $today   = now()->toDateString();

        // Two snapshots for the same member on the same day — only the later one should count
        $this->pushSnapshot($member, [$this->ticket('PROJ-1', 'gap')],  "{$today}T08:00:00Z");
        $this->pushSnapshot($member, [$this->ticket('PROJ-2', 'pass')], "{$today}T12:00:00Z");

        $this->actingAs($manager)
             ->get('/console/admin/compliance-analytics')
             ->assertOk()
             ->assertInertia(fn ($page) => $page->where('total_checked', 1));
    }
}
