<?php

namespace Tests\Feature\Owner;

use App\Models\User;
use App\Models\UserFeatureGrant;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamAccessControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
    }

    private function makeOwner(): User
    {
        return User::factory()->create(['is_owner' => true]);
    }

    // --- Store ---

    public function test_store_redirects_to_client_show(): void
    {
        $owner  = $this->makeOwner();
        $client = User::factory()->create(['tier' => 'pro']);

        $response = $this->actingAs($owner)->post(
            "/console/owner/clients/{$client->id}/team-access",
            ['seats' => 3],
        );

        $response->assertRedirect("/console/owner/clients/{$client->id}");
    }

    public function test_store_grants_team_access(): void
    {
        $owner  = $this->makeOwner();
        $client = User::factory()->create(['tier' => 'pro']);

        $this->actingAs($owner)->post("/console/owner/clients/{$client->id}/team-access", ['seats' => 3]);

        $this->assertNotNull($client->fresh()->ownedGroup);
        $this->assertSame(2, UserFeatureGrant::where('user_id', $client->id)->active()->count());
    }

    public function test_store_rejects_seats_below_two(): void
    {
        $owner  = $this->makeOwner();
        $client = User::factory()->create(['tier' => 'pro']);

        $response = $this->actingAs($owner)->post("/console/owner/clients/{$client->id}/team-access", ['seats' => 1]);

        $response->assertSessionHasErrors('seats');
        $this->assertNull($client->fresh()->ownedGroup);
    }

    public function test_store_rejects_non_integer_seats(): void
    {
        $owner  = $this->makeOwner();
        $client = User::factory()->create(['tier' => 'pro']);

        $response = $this->actingAs($owner)->post("/console/owner/clients/{$client->id}/team-access", ['seats' => 'lots']);

        $response->assertSessionHasErrors('seats');
    }

    public function test_store_rejects_past_expiry_date(): void
    {
        $owner  = $this->makeOwner();
        $client = User::factory()->create(['tier' => 'pro']);

        $response = $this->actingAs($owner)->post("/console/owner/clients/{$client->id}/team-access", [
            'seats'      => 3,
            'expires_at' => now()->subDay()->toDateString(),
        ]);

        $response->assertSessionHasErrors('expires_at');
    }

    public function test_store_rejects_team_tier_client_with_a_flash_error_not_a_500(): void
    {
        $owner  = $this->makeOwner();
        $client = User::factory()->create(['tier' => 'team']);

        $response = $this->actingAs($owner)->post("/console/owner/clients/{$client->id}/team-access", ['seats' => 3]);

        $response->assertSessionHasErrors();
        $this->assertNull($client->fresh()->ownedGroup);
    }

    public function test_non_owner_cannot_grant_team_access(): void
    {
        $notOwner = User::factory()->create(['is_owner' => false]);
        $client   = User::factory()->create(['tier' => 'pro']);

        $response = $this->actingAs($notOwner)->post("/console/owner/clients/{$client->id}/team-access", ['seats' => 3]);

        $response->assertRedirect('/console/dashboard');
        $this->assertNull($client->fresh()->ownedGroup);
    }

    // --- Destroy ---

    public function test_destroy_redirects_to_client_show(): void
    {
        $owner  = $this->makeOwner();
        $client = User::factory()->create(['tier' => 'pro']);
        $this->actingAs($owner)->post("/console/owner/clients/{$client->id}/team-access", ['seats' => 3]);

        $response = $this->actingAs($owner)->delete("/console/owner/clients/{$client->id}/team-access");

        $response->assertRedirect("/console/owner/clients/{$client->id}");
    }

    public function test_destroy_revokes_access_but_keeps_the_group(): void
    {
        $owner  = $this->makeOwner();
        $client = User::factory()->create(['tier' => 'pro']);
        $this->actingAs($owner)->post("/console/owner/clients/{$client->id}/team-access", ['seats' => 3]);
        $groupId = $client->fresh()->ownedGroup->id;

        $this->actingAs($owner)->delete("/console/owner/clients/{$client->id}/team-access");

        $this->assertSame(0, UserFeatureGrant::where('user_id', $client->id)->active()->count());
        $this->assertDatabaseHas('groups', ['id' => $groupId]);
    }

    public function test_non_owner_cannot_revoke_team_access(): void
    {
        $owner    = $this->makeOwner();
        $notOwner = User::factory()->create(['is_owner' => false]);
        $client   = User::factory()->create(['tier' => 'pro']);
        $this->actingAs($owner)->post("/console/owner/clients/{$client->id}/team-access", ['seats' => 3]);

        $response = $this->actingAs($notOwner)->delete("/console/owner/clients/{$client->id}/team-access");

        $response->assertRedirect('/console/dashboard');
        $this->assertSame(2, UserFeatureGrant::where('user_id', $client->id)->active()->count());
    }
}
