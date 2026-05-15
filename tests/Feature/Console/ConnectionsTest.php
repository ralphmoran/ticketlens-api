<?php

namespace Tests\Feature\Console;

use App\Models\TrackerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConnectionsTest extends TestCase
{
    use RefreshDatabase;

    // ── Lock tests — regression guards ────────────────────────────────────────

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/console/connections')->assertRedirect('/console/login');
    }

    public function test_free_tier_can_access_connections(): void
    {
        $user = User::factory()->create(['tier' => 'free', 'permissions' => 0]);
        $this->actingAs($user)->get('/console/connections')->assertOk();
    }

    // ── Feature tests ─────────────────────────────────────────────────────────

    public function test_index_returns_user_profiles_only(): void
    {
        $user  = User::factory()->create();
        $other = User::factory()->create();

        TrackerProfile::create([
            'user_id' => $user->id, 'name' => 'work', 'tracker_type' => 'jira',
            'base_url' => 'https://acme.atlassian.net', 'auth_method' => 'cloud',
        ]);
        TrackerProfile::create([
            'user_id' => $other->id, 'name' => 'other', 'tracker_type' => 'github',
            'base_url' => 'https://github.com/other/repo', 'auth_method' => 'github',
        ]);

        $this->actingAs($user)->get('/console/connections')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Console/Connections')
                ->where('profiles.0.name', 'work')
                ->count('profiles', 1)
            );
    }

    public function test_store_creates_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/console/connections', [
            'name'         => 'work',
            'tracker_type' => 'jira',
            'base_url'     => 'https://acme.atlassian.net',
            'auth_method'  => 'cloud',
            'email'        => 'me@acme.com',
        ])->assertRedirect('/console/connections');

        $this->assertDatabaseHas('tracker_profiles', [
            'user_id'      => $user->id,
            'name'         => 'work',
            'tracker_type' => 'jira',
            'email'        => 'me@acme.com',
        ]);
    }

    public function test_store_rejects_duplicate_name_for_same_user(): void
    {
        $user = User::factory()->create();
        TrackerProfile::create([
            'user_id' => $user->id, 'name' => 'work', 'tracker_type' => 'jira',
            'base_url' => 'https://acme.atlassian.net', 'auth_method' => 'cloud',
        ]);

        $this->actingAs($user)->post('/console/connections', [
            'name' => 'work', 'tracker_type' => 'jira',
            'base_url' => 'https://other.atlassian.net', 'auth_method' => 'cloud',
        ])->assertSessionHasErrors('name');
    }

    public function test_store_allows_same_name_for_different_users(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        TrackerProfile::create([
            'user_id' => $user1->id, 'name' => 'work', 'tracker_type' => 'jira',
            'base_url' => 'https://acme.atlassian.net', 'auth_method' => 'cloud',
        ]);

        $this->actingAs($user2)->post('/console/connections', [
            'name' => 'work', 'tracker_type' => 'jira',
            'base_url' => 'https://other.atlassian.net', 'auth_method' => 'cloud',
        ])->assertRedirect('/console/connections');

        $this->assertDatabaseCount('tracker_profiles', 2);
    }

    public function test_update_changes_profile(): void
    {
        $user    = User::factory()->create();
        $profile = TrackerProfile::create([
            'user_id' => $user->id, 'name' => 'work', 'tracker_type' => 'jira',
            'base_url' => 'https://acme.atlassian.net', 'auth_method' => 'cloud',
        ]);

        $this->actingAs($user)->put("/console/connections/{$profile->id}", [
            'name'        => 'work',
            'tracker_type'=> 'jira',
            'base_url'    => 'https://new.atlassian.net',
            'auth_method' => 'cloud',
        ])->assertRedirect('/console/connections');

        $this->assertDatabaseHas('tracker_profiles', ['base_url' => 'https://new.atlassian.net']);
    }

    public function test_update_rejects_other_users_profile(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $profile = TrackerProfile::create([
            'user_id' => $owner->id, 'name' => 'work', 'tracker_type' => 'jira',
            'base_url' => 'https://acme.atlassian.net', 'auth_method' => 'cloud',
        ]);

        $this->actingAs($other)->put("/console/connections/{$profile->id}", [
            'name' => 'work', 'tracker_type' => 'jira',
            'base_url' => 'https://hacked.atlassian.net', 'auth_method' => 'cloud',
        ])->assertForbidden();
    }

    public function test_destroy_removes_profile(): void
    {
        $user    = User::factory()->create();
        $profile = TrackerProfile::create([
            'user_id' => $user->id, 'name' => 'work', 'tracker_type' => 'jira',
            'base_url' => 'https://acme.atlassian.net', 'auth_method' => 'cloud',
        ]);

        $this->actingAs($user)->delete("/console/connections/{$profile->id}")
            ->assertRedirect('/console/connections');

        $this->assertDatabaseMissing('tracker_profiles', ['id' => $profile->id]);
    }

    public function test_destroy_rejects_other_users_profile(): void
    {
        $owner   = User::factory()->create();
        $other   = User::factory()->create();
        $profile = TrackerProfile::create([
            'user_id' => $owner->id, 'name' => 'work', 'tracker_type' => 'jira',
            'base_url' => 'https://acme.atlassian.net', 'auth_method' => 'cloud',
        ]);

        $this->actingAs($other)->delete("/console/connections/{$profile->id}")->assertForbidden();
        $this->assertDatabaseHas('tracker_profiles', ['id' => $profile->id]);
    }
}
