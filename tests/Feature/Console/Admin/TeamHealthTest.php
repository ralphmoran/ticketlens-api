<?php

namespace Tests\Feature\Console\Admin;

use App\Models\Group;
use App\Models\TriageSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamHealthTest extends TestCase
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

    private function ticket(string $key, string $status = 'In Progress', array $flags = []): array
    {
        return [
            'key'                 => $key,
            'summary'             => "Summary for {$key}",
            'status'              => $status,
            'assignee'            => 'Dev',
            'attention_score'     => 5.0,
            'flags'               => $flags,
            'compliance_coverage' => null,
            'compliance_status'   => 'unknown',
            'url'                 => "https://jira.example.com/browse/{$key}",
            'last_updated'        => '2026-05-12T09:00:00Z',
        ];
    }

    // --- Access control (LOCK tests — these must pass before any new code) ---

    public function test_guest_redirected_to_login(): void
    {
        $this->get('/console/admin/team-health')->assertRedirect('/console/login');
    }

    public function test_team_member_without_manager_bit_redirected_to_dashboard(): void
    {
        $user = User::factory()->create(['tier' => 'team', 'permissions' => 127]);

        $this->actingAs($user)->get('/console/admin/team-health')
            ->assertRedirect('/console/dashboard');
    }

    public function test_user_with_manager_bit_but_no_group_at_all_gets_403(): void
    {
        // Middleware passes (has manager bit), controller aborts — no group to show.
        $user = User::factory()->create(['tier' => 'team', 'permissions' => 511]);

        $this->actingAs($user)->get('/console/admin/team-health')
            ->assertStatus(403);
    }

    public function test_manager_can_access_team_health_page(): void
    {
        $manager = $this->makeManager();

        $this->actingAs($manager)->get('/console/admin/team-health')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page->component('Console/Admin/TeamHealth'));
    }

    // --- Data scoping (LOCK — tenant isolation must hold) ---

    public function test_manager_cannot_see_other_groups_snapshot_data(): void
    {
        $manager      = $this->makeManager();
        $otherManager = $this->makeManager();
        $otherMember  = $this->makeMember($otherManager->ownedGroup);

        $this->pushSnapshot($otherMember, [
            $this->ticket('OTHER-1', 'In Progress', ['needs-response']),
        ]);

        $this->actingAs($manager)->get('/console/admin/team-health')
            ->assertInertia(fn ($page) => $page
                ->where('needs_response', [])
                ->where('bottlenecks', [])
            );
    }

    // --- Workload aggregation ---

    public function test_workload_includes_all_group_members_even_without_snapshot(): void
    {
        // Manager name starts with Z to ensure Alice sorts first (alphabetical tiebreak when both have 0 tickets)
        $manager = $this->makeManager('Zara Manager');
        $dev     = $this->makeMember($manager->ownedGroup, 'Alice');

        $this->actingAs($manager)->get('/console/admin/team-health')
            ->assertInertia(fn ($page) => $page
                ->has('workload', 2) // Alice + Zara Manager
                ->where('workload.0.member_name', 'Alice')
                ->where('workload.0.ticket_count', 0)
                ->where('workload.0.needs_response_count', 0)
                ->where('workload.0.last_push', null)
            );
    }

    public function test_workload_counts_tickets_from_member_snapshots(): void
    {
        $manager = $this->makeManager();
        $dev     = $this->makeMember($manager->ownedGroup, 'Bob');

        $this->pushSnapshot($dev, [
            $this->ticket('BOB-1'),
            $this->ticket('BOB-2'),
            $this->ticket('BOB-3', 'Code Review', ['needs-response']),
        ]);

        $this->actingAs($manager)->get('/console/admin/team-health')
            ->assertInertia(fn ($page) => $page
                ->where('workload.0.member_name', 'Bob')
                ->where('workload.0.ticket_count', 3)
                ->where('workload.0.needs_response_count', 1)
            );
    }

    public function test_workload_aggregates_multiple_profiles_for_same_member(): void
    {
        $manager = $this->makeManager();
        $dev     = $this->makeMember($manager->ownedGroup);

        $this->pushSnapshot($dev, [$this->ticket('P-1'), $this->ticket('P-2')], 'production');
        $this->pushSnapshot($dev, [$this->ticket('S-1')], 'staging');

        $this->actingAs($manager)->get('/console/admin/team-health')
            ->assertInertia(fn ($page) => $page
                ->where('workload.0.ticket_count', 3)
            );
    }

    // --- Needs-response panel ---

    public function test_needs_response_contains_only_flagged_tickets(): void
    {
        $manager = $this->makeManager();
        $dev     = $this->makeMember($manager->ownedGroup);

        $this->pushSnapshot($dev, [
            $this->ticket('NR-1', 'Code Review', ['needs-response']),
            $this->ticket('NR-2', 'In Progress'),
            $this->ticket('NR-3', 'QA', ['needs-response']),
        ]);

        $this->actingAs($manager)->get('/console/admin/team-health')
            ->assertInertia(fn ($page) => $page
                ->has('needs_response', 2)
                ->where('needs_response.0.key', 'NR-1')
                ->where('needs_response.1.key', 'NR-3')
            );
    }

    public function test_needs_response_includes_member_name(): void
    {
        $manager = $this->makeManager();
        $dev     = $this->makeMember($manager->ownedGroup, 'Carol');

        $this->pushSnapshot($dev, [
            $this->ticket('C-1', 'Code Review', ['needs-response']),
        ]);

        $this->actingAs($manager)->get('/console/admin/team-health')
            ->assertInertia(fn ($page) => $page
                ->where('needs_response.0.member_name', 'Carol')
            );
    }

    // --- Bottlenecks panel ---

    public function test_bottlenecks_groups_tickets_by_status(): void
    {
        $manager = $this->makeManager();
        $dev     = $this->makeMember($manager->ownedGroup);

        $this->pushSnapshot($dev, [
            $this->ticket('T-1', 'Code Review'),
            $this->ticket('T-2', 'Code Review'),
            $this->ticket('T-3', 'QA'),
        ]);

        $this->actingAs($manager)->get('/console/admin/team-health')
            ->assertInertia(fn ($page) => $page
                ->has('bottlenecks', 2)
                ->where('bottlenecks.0.status', 'Code Review')
                ->where('bottlenecks.0.count', 2)
                ->where('bottlenecks.1.status', 'QA')
                ->where('bottlenecks.1.count', 1)
            );
    }

    // --- Owner access ---

    public function test_owner_sees_search_state_without_manager_id(): void
    {
        $owner   = User::factory()->create(['is_owner' => true, 'permissions' => 0]);
        $manager = $this->makeManager('Carol');

        $this->actingAs($owner)
            ->get('/console/admin/team-health')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Console/Admin/TeamHealth')
                ->where('owner_mode', true)
                ->where('selected_manager', null)
                ->where('workload', [])
                ->has('clients', 1)
                ->where('clients.0.name', 'Carol')
                ->where('clients.0.avatar_url', null)
            );
    }

    public function test_owner_with_valid_manager_id_sees_that_teams_data(): void
    {
        $owner   = User::factory()->create(['is_owner' => true, 'permissions' => 0]);
        $manager = $this->makeManager('Dave');
        $this->pushSnapshot($manager, [$this->ticket('P-1', flags: ['needs-response'])]);

        $this->actingAs($owner)
            ->get("/console/admin/team-health?manager_id={$manager->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('owner_mode', true)
                ->where('selected_manager.name', 'Dave')
                ->has('needs_response', 1)
            );
    }

    public function test_owner_with_invalid_manager_id_redirects_to_team_health(): void
    {
        $owner = User::factory()->create(['is_owner' => true, 'permissions' => 0]);

        $this->actingAs($owner)
            ->get('/console/admin/team-health?manager_id=99999')
            ->assertRedirect('/console/admin/team-health');
    }

    // --- Historical row dedup (lock: latest-per-user-profile must be used) ---

    public function test_workload_uses_latest_snapshot_per_user_profile(): void
    {
        $manager = $this->makeManager();
        $dev     = $this->makeMember($manager->ownedGroup, 'Dave');

        // Older snapshot: 5 tickets (should NOT count)
        TriageSnapshot::create([
            'user_id'      => $dev->id,
            'profile'      => 'production',
            'tickets'      => array_map(fn ($i) => $this->ticket("OLD-$i"), range(1, 5)),
            'ticket_count' => 5,
            'captured_at'  => now()->subDays(2),
        ]);

        // Latest snapshot: 2 tickets (SHOULD count)
        TriageSnapshot::create([
            'user_id'      => $dev->id,
            'profile'      => 'production',
            'tickets'      => [$this->ticket('NEW-1'), $this->ticket('NEW-2')],
            'ticket_count' => 2,
            'captured_at'  => now(),
        ]);

        $this->actingAs($manager)->get('/console/admin/team-health')
            ->assertInertia(fn ($page) => $page
                ->where('workload.0.ticket_count', 2)
                ->where('workload.0.member_name', 'Dave')
            );
    }

    // --- Empty state ---

    public function test_empty_page_when_no_member_has_pushed(): void
    {
        $manager = $this->makeManager();
        $this->makeMember($manager->ownedGroup);

        $this->actingAs($manager)->get('/console/admin/team-health')
            ->assertInertia(fn ($page) => $page
                ->where('needs_response', [])
                ->where('bottlenecks', [])
                ->where('last_updated', null)
            );
    }
}
