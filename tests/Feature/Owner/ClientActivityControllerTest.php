<?php

namespace Tests\Feature\Owner;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClientActivityControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeOwner(): User
    {
        return User::factory()->create(['is_owner' => true]);
    }

    private function insertCliLog(int $userId, string $action, int $tokensSaved, ?string $ticketKey = null, int $daysAgo = 0): void
    {
        DB::table('usage_logs')->insert([
            'user_id'    => $userId,
            'action'     => $action,
            'ticket_key' => $ticketKey,
            'tokens_used'=> $tokensSaved,
            'metadata'   => json_encode(['count' => 1, 'flags' => []]),
            'created_at' => now()->subDays($daysAgo)->toDateTimeString(),
        ]);
    }

    // ── RED ────────────────────────────────────────────────────────────────

    // RED: non-owner is redirected — owner middleware must guard the activity route
    public function test_red_non_owner_redirected_from_activity(): void
    {
        $user = User::factory()->create(['tier' => 'pro']);
        $this->actingAs($user)->get('/console/owner/activity')->assertRedirect('/console/dashboard');
    }

    // RED: guest is redirected to login (auth middleware)
    public function test_red_guest_redirected_from_activity(): void
    {
        $this->get('/console/owner/activity')->assertRedirect('/console/login');
    }

    // RED: owner can view the client activity page (route + controller + component must exist)
    public function test_red_owner_can_view_activity_page(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)
            ->get('/console/owner/activity')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Console/Owner/Activity'));
    }

    // RED: activity page returns rows prop with recent usage_logs entries
    public function test_red_activity_page_includes_logs_from_usage_logs_table(): void
    {
        $owner  = $this->makeOwner();
        $client = User::factory()->create(['tier' => 'pro']);

        $this->insertCliLog($client->id, 'triage', 1200, 'PROJ-1', 0);

        $this->actingAs($owner)
            ->get('/console/owner/activity')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Console/Owner/Activity')
                ->has('rows')
                ->where('rows.0.action', 'triage')
                ->where('rows.0.tokens_used', 1200)
            );
    }
}
