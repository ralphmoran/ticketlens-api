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

    private function insertAiConsumedLog(int $userId, string $action, int $tokensConsumed): void
    {
        DB::table('usage_logs')->insert([
            'user_id'    => $userId,
            'action'     => $action,
            'ticket_key' => null,
            'tokens_used'=> $tokensConsumed,
            'metadata'   => null,
            'created_at' => now()->toDateTimeString(),
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

    // RED: activity page returns paginated logs prop with recent usage_logs entries
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
                ->has('logs.data')
                ->where('logs.data.0.action', 'triage')
                ->where('logs.data.0.tokens_used', 1200)
                ->where('logs.data.0.user.email', $client->email)
            );
    }

    public function test_search_filters_by_client_email_or_name(): void
    {
        $owner   = $this->makeOwner();
        $match   = User::factory()->create(['tier' => 'pro', 'name' => 'Jane Roe', 'email' => 'jane@example.com']);
        $noMatch = User::factory()->create(['tier' => 'pro', 'name' => 'Bob Doe', 'email' => 'bob@example.com']);

        $this->insertCliLog($match->id, 'triage', 500, 'PROJ-1');
        $this->insertCliLog($noMatch->id, 'triage', 500, 'PROJ-2');

        $this->actingAs($owner)
            ->get('/console/owner/activity?search=jane')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('logs.data', 1)
                ->where('logs.data.0.user.email', 'jane@example.com')
            );
    }

    public function test_search_filters_by_action_or_ticket_key(): void
    {
        $owner  = $this->makeOwner();
        $client = User::factory()->create(['tier' => 'pro']);

        $this->insertCliLog($client->id, 'triage', 500, 'PROJ-1');
        $this->insertCliLog($client->id, 'digest', 500, 'PROJ-2');

        $this->actingAs($owner)
            ->get('/console/owner/activity?search=PROJ-2')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('logs.data', 1)
                ->where('logs.data.0.action', 'digest')
            );
    }

    public function test_excludes_ai_consumed_rows_without_metadata(): void
    {
        $owner  = $this->makeOwner();
        $client = User::factory()->create(['tier' => 'pro']);

        $this->insertCliLog($client->id, 'triage', 500, 'PROJ-1');
        $this->insertAiConsumedLog($client->id, 'digest_send', 3000);

        $this->actingAs($owner)
            ->get('/console/owner/activity')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('logs.data', 1)
                ->where('logs.data.0.action', 'triage')
            );
    }

    public function test_pagination_defaults_to_ten_per_page(): void
    {
        $owner  = $this->makeOwner();
        $client = User::factory()->create(['tier' => 'pro']);

        for ($i = 0; $i < 15; $i++) {
            $this->insertCliLog($client->id, 'triage', 100, "PROJ-{$i}");
        }

        $this->actingAs($owner)
            ->get('/console/owner/activity')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('logs.data', 10)
                ->where('logs.total', 15)
                ->where('logs.per_page', 10)
            );
    }

    public function test_per_page_is_capped_at_100(): void
    {
        $owner  = $this->makeOwner();
        $client = User::factory()->create(['tier' => 'pro']);

        $this->insertCliLog($client->id, 'triage', 100, 'PROJ-1');

        $this->actingAs($owner)
            ->get('/console/owner/activity?per_page=500')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('logs.per_page', 100)
            );
    }
}
