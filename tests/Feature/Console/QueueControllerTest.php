<?php

namespace Tests\Feature\Console;

use App\Enums\Permission;
use App\Models\TriageSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QueueControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeTeamUser(): User
    {
        return User::factory()->create([
            'tier'        => 'team',
            'permissions' => Permission::team(),
        ]);
    }

    private function makeProUser(): User
    {
        return User::factory()->create([
            'tier'        => 'pro',
            'permissions' => Permission::pro(),
        ]);
    }

    private function addSnapshot(User $user, string $profile = 'production', int $ticketCount = 3): TriageSnapshot
    {
        return TriageSnapshot::create([
            'user_id'          => $user->id,
            'license_key_hash' => hash('sha256', 'some-key'),
            'profile'          => $profile,
            'tickets'          => array_map(fn ($i) => [
                'key'                 => "PROJ-{$i}",
                'summary'             => "Ticket {$i}",
                'status'              => 'Code Review',
                'assignee'            => 'Dev',
                'attention_score'     => 7.0,
                'flags'               => ['needs-response'],
                'compliance_coverage' => null,
                'compliance_status'   => 'unknown',
                'url'                 => "https://jira.example.com/browse/PROJ-{$i}",
                'last_updated'        => '2026-05-11T09:00:00Z',
            ], range(1, $ticketCount)),
            'ticket_count'     => $ticketCount,
            'captured_at'      => now(),
        ]);
    }

    public function test_team_user_can_access_queue_page(): void
    {
        $user = $this->makeTeamUser();

        $response = $this->actingAs($user)->get('/console/queue');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Console/Queue'));
    }

    public function test_pro_user_cannot_access_queue_page(): void
    {
        $user = $this->makeProUser();

        $response = $this->actingAs($user)->get('/console/queue');

        $response->assertRedirect('/console/upgrade');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get('/console/queue');

        $response->assertRedirect('/console/login');
    }

    public function test_snapshots_are_passed_to_the_page(): void
    {
        $user = $this->makeTeamUser();
        // Use distinct captured_at to guarantee deterministic ordering (DESC)
        TriageSnapshot::create(array_merge($this->snapshotData($user, 'staging', 1), [
            'captured_at' => now()->subMinutes(5),
        ]));
        TriageSnapshot::create(array_merge($this->snapshotData($user, 'production', 3), [
            'captured_at' => now(),
        ]));

        $response = $this->actingAs($user)->get('/console/queue');

        $response->assertInertia(fn ($page) => $page
            ->has('snapshots.data', 2)
            ->where('snapshots.data.0.profile', 'production')
            ->where('snapshots.data.0.ticket_count', 3)
            ->has('snapshots.current_page')
            ->has('snapshots.total')
        );
    }

    public function test_queue_paginates_at_10_per_page(): void
    {
        $user = $this->makeTeamUser();
        for ($i = 0; $i < 12; $i++) {
            TriageSnapshot::create(array_merge($this->snapshotData($user, 'production', 1), [
                'captured_at' => now()->subMinutes($i),
            ]));
        }

        $response = $this->actingAs($user)->get('/console/queue');

        $response->assertInertia(fn ($page) => $page
            ->has('snapshots.data', 10)
            ->where('snapshots.total', 12)
            ->where('snapshots.last_page', 2)
        );
    }

    private function snapshotData(User $user, string $profile, int $ticketCount): array
    {
        return [
            'user_id'          => $user->id,
            'license_key_hash' => hash('sha256', 'some-key'),
            'profile'          => $profile,
            'tickets'          => array_map(fn ($i) => [
                'key'                 => "PROJ-{$i}",
                'summary'             => "Ticket {$i}",
                'status'              => 'Code Review',
                'assignee'            => 'Dev',
                'attention_score'     => 7.0,
                'flags'               => ['needs-response'],
                'compliance_coverage' => null,
                'compliance_status'   => 'unknown',
                'url'                 => "https://jira.example.com/browse/PROJ-{$i}",
                'last_updated'        => '2026-05-11T09:00:00Z',
            ], range(1, $ticketCount)),
            'ticket_count' => $ticketCount,
        ];
    }

    public function test_per_page_param_is_respected(): void
    {
        $user = $this->makeTeamUser();
        for ($i = 0; $i < 12; $i++) {
            TriageSnapshot::create(array_merge($this->snapshotData($user, 'production', 1), [
                'captured_at' => now()->subMinutes($i),
            ]));
        }

        $this->actingAs($user)->get('/console/queue?per_page=25')
            ->assertInertia(fn ($page) => $page
                ->has('snapshots.data', 12)
                ->where('snapshots.last_page', 1)
            );
    }

    public function test_empty_snapshots_passed_when_no_push_yet(): void
    {
        $user = $this->makeTeamUser();

        $response = $this->actingAs($user)->get('/console/queue');

        $response->assertInertia(fn ($page) => $page->has('snapshots.data', 0));
    }

    public function test_only_own_snapshots_are_visible(): void
    {
        $user  = $this->makeTeamUser();
        $other = $this->makeTeamUser();
        $this->addSnapshot($other, 'production');

        $response = $this->actingAs($user)->get('/console/queue');

        $response->assertInertia(fn ($page) => $page->has('snapshots.data', 0));
    }
}
