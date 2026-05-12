<?php

namespace Tests\Feature\Console\Admin;

use App\Models\Group;
use App\Models\TriageSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessMetricsTest extends TestCase
{
    use RefreshDatabase;

    // --- Helpers ---

    private function makeManager(string $name = null): User
    {
        $manager = User::factory()->create([
            'tier'        => 'team',
            'permissions' => 511, // team() | teamManagerMask()
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

    private function pushSnapshot(User $user, array $tickets, string $profile = 'production'): TriageSnapshot
    {
        return TriageSnapshot::create([
            'user_id'          => $user->id,
            'license_key_hash' => hash('sha256', "key-{$user->id}-{$profile}"),
            'profile'          => $profile,
            'tickets'          => $tickets,
            'ticket_count'     => count($tickets),
            'captured_at'      => now(),
        ]);
    }

    private function ticket(
        string $key,
        string $status = 'In Progress',
        array $flags = [],
        ?string $lastUpdated = null,
        ?float $complianceCoverage = null,
        string $complianceStatus = 'unknown',
    ): array {
        return [
            'key'                 => $key,
            'summary'             => "Summary of $key",
            'status'              => $status,
            'assignee'            => null,
            'flags'               => $flags,
            'attention_score'     => null,
            'compliance_coverage' => $complianceCoverage,
            'compliance_status'   => $complianceStatus,
            'url'                 => "https://jira.example.com/browse/$key",
            'last_updated'        => $lastUpdated ?? now()->toIso8601String(),
        ];
    }

    // --- Lock tests (gate + empty state must remain stable) ---

    public function test_owner_sees_search_state_without_manager_id(): void
    {
        $owner   = User::factory()->create(['is_owner' => true, 'permissions' => 0]);
        $manager = $this->makeManager('Alice');

        $this->actingAs($owner)
            ->get('/console/admin/process-metrics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Console/Admin/ProcessMetrics')
                ->where('owner_mode', true)
                ->where('selected_manager', null)
                ->where('velocity', [])
                ->where('compliance', [])
                ->has('clients', 1)
                ->where('clients.0.name', 'Alice')
            );
    }

    public function test_owner_with_valid_manager_id_sees_that_teams_data(): void
    {
        $this->freezeTime();
        $owner   = User::factory()->create(['is_owner' => true, 'permissions' => 0]);
        $manager = $this->makeManager('Bob');
        $this->pushSnapshot($manager, [
            $this->ticket('P-1', lastUpdated: now()->subHours(6)->toIso8601String()),
        ]);

        $this->actingAs($owner)
            ->get("/console/admin/process-metrics?manager_id={$manager->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('owner_mode', true)
                ->where('selected_manager.name', 'Bob')
                ->has('velocity', 1)
                ->where('velocity.0.fresh', 1)
            );
    }

    public function test_owner_with_invalid_manager_id_redirects_to_process_metrics(): void
    {
        $owner = User::factory()->create(['is_owner' => true, 'permissions' => 0]);

        $this->actingAs($owner)
            ->get('/console/admin/process-metrics?manager_id=99999')
            ->assertRedirect('/console/admin/process-metrics');
    }

    public function test_unauthenticated_redirects_to_console_login(): void
    {
        $this->get('/console/admin/process-metrics')
            ->assertRedirect('/console/login');
    }

    public function test_non_manager_redirects_to_dashboard(): void
    {
        $user = User::factory()->create(['tier' => 'team', 'permissions' => 127]);

        $this->actingAs($user)
            ->get('/console/admin/process-metrics')
            ->assertRedirect('/console/dashboard');
    }

    public function test_manager_with_no_snapshots_returns_empty_collections(): void
    {
        $manager = $this->makeManager();

        $this->actingAs($manager)
            ->get('/console/admin/process-metrics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Console/Admin/ProcessMetrics')
                ->where('velocity', [])
                ->where('status_flow', [])
                ->where('response_latency.total', 0)
            );
    }

    // --- Velocity (age-bucket) tests ---

    public function test_velocity_bucket_fresh_under_24_hours(): void
    {
        $this->freezeTime();
        $manager = $this->makeManager();
        $this->pushSnapshot($manager, [
            $this->ticket('P-1', lastUpdated: now()->subHours(12)->toIso8601String()),
        ]);

        $this->actingAs($manager)
            ->get('/console/admin/process-metrics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('velocity.0.fresh', 1)
                ->where('velocity.0.active', 0)
                ->where('velocity.0.total', 1)
            );
    }

    public function test_velocity_bucket_active_at_two_days(): void
    {
        $this->freezeTime();
        $manager = $this->makeManager();
        $this->pushSnapshot($manager, [
            $this->ticket('P-1', lastUpdated: now()->subDays(2)->toIso8601String()),
        ]);

        $this->actingAs($manager)
            ->get('/console/admin/process-metrics')
            ->assertInertia(fn ($page) => $page
                ->where('velocity.0.fresh', 0)
                ->where('velocity.0.active', 1)
                ->where('velocity.0.slowing', 0)
            );
    }

    public function test_velocity_bucket_slowing_at_five_days(): void
    {
        $this->freezeTime();
        $manager = $this->makeManager();
        $this->pushSnapshot($manager, [
            $this->ticket('P-1', lastUpdated: now()->subDays(5)->toIso8601String()),
        ]);

        $this->actingAs($manager)
            ->get('/console/admin/process-metrics')
            ->assertInertia(fn ($page) => $page
                ->where('velocity.0.slowing', 1)
                ->where('velocity.0.stale', 0)
            );
    }

    public function test_velocity_bucket_stale_at_ten_days(): void
    {
        $this->freezeTime();
        $manager = $this->makeManager();
        $this->pushSnapshot($manager, [
            $this->ticket('P-1', lastUpdated: now()->subDays(10)->toIso8601String()),
        ]);

        $this->actingAs($manager)
            ->get('/console/admin/process-metrics')
            ->assertInertia(fn ($page) => $page
                ->where('velocity.0.stale', 1)
                ->where('velocity.0.abandoned', 0)
            );
    }

    public function test_velocity_bucket_abandoned_at_fifteen_days(): void
    {
        $this->freezeTime();
        $manager = $this->makeManager();
        $this->pushSnapshot($manager, [
            $this->ticket('P-1', lastUpdated: now()->subDays(15)->toIso8601String()),
        ]);

        $this->actingAs($manager)
            ->get('/console/admin/process-metrics')
            ->assertInertia(fn ($page) => $page
                ->where('velocity.0.abandoned', 1)
                ->where('velocity.0.stale', 0)
            );
    }

    public function test_velocity_null_last_updated_counts_as_abandoned(): void
    {
        $manager = $this->makeManager();
        $ticket  = $this->ticket('P-1');
        $ticket['last_updated'] = null;
        $this->pushSnapshot($manager, [$ticket]);

        $this->actingAs($manager)
            ->get('/console/admin/process-metrics')
            ->assertInertia(fn ($page) => $page
                ->where('velocity.0.abandoned', 1)
            );
    }

    public function test_velocity_includes_row_per_member(): void
    {
        $this->freezeTime();
        $manager = $this->makeManager();
        $member  = $this->makeMember($manager->ownedGroup);

        $this->pushSnapshot($manager, [
            $this->ticket('MGR-1', lastUpdated: now()->subHours(6)->toIso8601String()),
        ]);
        $this->pushSnapshot($member, [
            $this->ticket('MBR-1', lastUpdated: now()->subDays(5)->toIso8601String()),
        ]);

        $this->actingAs($manager)
            ->get('/console/admin/process-metrics')
            ->assertInertia(fn ($page) => $page
                ->has('velocity', 2)
            );
    }

    public function test_velocity_sorted_by_total_descending(): void
    {
        $this->freezeTime();
        $manager = $this->makeManager();
        $member  = $this->makeMember($manager->ownedGroup);

        $this->pushSnapshot($manager, [
            $this->ticket('MGR-1'),
            $this->ticket('MGR-2'),
            $this->ticket('MGR-3'),
        ]);
        $this->pushSnapshot($member, [
            $this->ticket('MBR-1'),
        ]);

        $this->actingAs($manager)
            ->get('/console/admin/process-metrics')
            ->assertInertia(fn ($page) => $page
                ->where('velocity.0.total', 3)
                ->where('velocity.1.total', 1)
            );
    }

    // --- Status flow tests ---

    public function test_status_flow_groups_by_status_and_age(): void
    {
        $this->freezeTime();
        $manager = $this->makeManager();
        $this->pushSnapshot($manager, [
            $this->ticket('P-1', 'In Progress', lastUpdated: now()->subHours(6)->toIso8601String()),
            $this->ticket('P-2', 'In Progress', lastUpdated: now()->subDays(5)->toIso8601String()),
            $this->ticket('P-3', 'In Review',   lastUpdated: now()->subHours(2)->toIso8601String()),
        ]);

        $this->actingAs($manager)
            ->get('/console/admin/process-metrics')
            ->assertInertia(fn ($page) => $page
                ->has('status_flow', 2)
                ->where('status_flow.0.status', 'In Progress')
                ->where('status_flow.0.total', 2)
                ->where('status_flow.0.fresh', 1)
                ->where('status_flow.0.slowing', 1)
                ->where('status_flow.1.status', 'In Review')
                ->where('status_flow.1.total', 1)
            );
    }

    public function test_status_flow_sorted_by_total_descending(): void
    {
        $this->freezeTime();
        $manager = $this->makeManager();
        $this->pushSnapshot($manager, [
            $this->ticket('P-1', 'Done'),
            $this->ticket('P-2', 'In Progress'),
            $this->ticket('P-3', 'In Progress'),
        ]);

        $this->actingAs($manager)
            ->get('/console/admin/process-metrics')
            ->assertInertia(fn ($page) => $page
                ->where('status_flow.0.status', 'In Progress')
                ->where('status_flow.0.total', 2)
            );
    }

    // --- Response latency tests ---

    public function test_response_latency_counts_flagged_tickets_by_age(): void
    {
        $this->freezeTime();
        $manager = $this->makeManager();
        $this->pushSnapshot($manager, [
            $this->ticket('P-1', flags: ['needs-response'], lastUpdated: now()->subHours(6)->toIso8601String()),
            $this->ticket('P-2', flags: ['needs-response'], lastUpdated: now()->subDays(5)->toIso8601String()),
            $this->ticket('P-3', flags: []),
        ]);

        $this->actingAs($manager)
            ->get('/console/admin/process-metrics')
            ->assertInertia(fn ($page) => $page
                ->where('response_latency.total', 2)
                ->where('response_latency.fresh', 1)
                ->where('response_latency.slowing', 1)
                ->where('response_latency.active', 0)
            );
    }

    public function test_response_latency_zero_when_no_flagged_tickets(): void
    {
        $manager = $this->makeManager();
        $this->pushSnapshot($manager, [$this->ticket('P-1', flags: [])]);

        $this->actingAs($manager)
            ->get('/console/admin/process-metrics')
            ->assertInertia(fn ($page) => $page
                ->where('response_latency.total', 0)
                ->where('response_latency.stale', 0)
            );
    }

    // --- Compliance tests ---

    public function test_compliance_shows_zero_when_all_statuses_unknown(): void
    {
        $manager = $this->makeManager();
        $this->pushSnapshot($manager, [
            $this->ticket('P-1', complianceStatus: 'unknown'),
            $this->ticket('P-2', complianceStatus: 'unknown'),
        ]);

        $this->actingAs($manager)
            ->get('/console/admin/process-metrics')
            ->assertInertia(fn ($page) => $page
                ->where('compliance.0.checked', 0)
                ->where('compliance.0.coverage_pct', 0)   // json_encode(0.0) = 0
                ->where('compliance.0.avg_coverage', null)
            );
    }

    public function test_compliance_counts_non_unknown_tickets_as_checked(): void
    {
        $manager = $this->makeManager();
        $this->pushSnapshot($manager, [
            // P-2 coverage 61 not 60 — keeps avg non-integer (70.5) to avoid JSON int coercion
            $this->ticket('P-1', complianceCoverage: 80.0, complianceStatus: 'pass'),
            $this->ticket('P-2', complianceCoverage: 61.0, complianceStatus: 'fail'),
            $this->ticket('P-3', complianceStatus: 'unknown'),
        ]);

        $this->actingAs($manager)
            ->get('/console/admin/process-metrics')
            ->assertInertia(fn ($page) => $page
                ->where('compliance.0.total', 3)
                ->where('compliance.0.checked', 2)
                ->where('compliance.0.coverage_pct', 66.7)
                ->where('compliance.0.avg_coverage', 70.5)
            );
    }

    // --- Inertia render test ---

    public function test_page_renders_with_group_name_and_last_updated(): void
    {
        $manager = $this->makeManager('Alice');
        $this->pushSnapshot($manager, [$this->ticket('P-1')]);

        $this->actingAs($manager)
            ->get('/console/admin/process-metrics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Console/Admin/ProcessMetrics')
                ->has('group_name')
                ->has('last_updated')
            );
    }
}
