<?php

namespace Tests\Feature\Api;

use App\Models\CliToken;
use App\Models\TriageSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatusCacheControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::factory()->create(['tier' => 'pro', 'permissions' => 2119]);
    }

    private function makeToken(User $user): string
    {
        $plaintext = 'tl_' . str_repeat('b', 40);
        CliToken::create([
            'user_id'    => $user->id,
            'name'       => 'CLI Token',
            'token_hash' => CliToken::hashToken($plaintext),
        ]);
        return $plaintext;
    }

    private function makeSnapshot(User $user, array $tickets): TriageSnapshot
    {
        return TriageSnapshot::create([
            'user_id'          => $user->id,
            'license_key_hash' => hash('sha256', 'test-key'),
            'profile'          => 'work',
            'tickets'          => $tickets,
            'ticket_count'     => count($tickets),
            'captured_at'      => now(),
        ]);
    }

    public function test_returns_401_without_token(): void
    {
        $this->getJson('/v1/statuses')->assertStatus(401);
    }

    public function test_returns_401_with_invalid_token(): void
    {
        $this->withToken('tl_invalid')->getJson('/v1/statuses')->assertStatus(401);
    }

    public function test_returns_empty_array_when_no_snapshots(): void
    {
        $user  = $this->makeUser();
        $token = $this->makeToken($user);

        $response = $this->withToken($token)->getJson('/v1/statuses');
        $response->assertOk()->assertJson(['statuses' => []]);
    }

    public function test_returns_distinct_sorted_statuses_from_snapshots(): void
    {
        $user  = $this->makeUser();
        $token = $this->makeToken($user);

        $this->makeSnapshot($user, [
            ['key' => 'PROJ-1', 'status' => 'In Review'],
            ['key' => 'PROJ-2', 'status' => 'In Progress'],
        ]);
        $this->makeSnapshot($user, [
            ['key' => 'PROJ-3', 'status' => 'In Review'],   // duplicate
            ['key' => 'PROJ-4', 'status' => 'Code Review'],
        ]);

        $response  = $this->withToken($token)->getJson('/v1/statuses');
        $statuses  = $response->assertOk()->json('statuses');

        $this->assertEquals(['Code Review', 'In Progress', 'In Review'], $statuses);
    }

    public function test_does_not_return_other_users_statuses(): void
    {
        $user  = $this->makeUser();
        $other = $this->makeUser();
        $token = $this->makeToken($user);

        $this->makeSnapshot($other, [['key' => 'OTH-1', 'status' => 'Secret Status']]);

        $response = $this->withToken($token)->getJson('/v1/statuses');
        $response->assertOk();
        $this->assertNotContains('Secret Status', $response->json('statuses'));
    }

    public function test_excludes_snapshots_older_than_30_days(): void
    {
        $user  = $this->makeUser();
        $token = $this->makeToken($user);

        TriageSnapshot::create([
            'user_id'          => $user->id,
            'license_key_hash' => hash('sha256', 'key'),
            'profile'          => 'work',
            'tickets'          => [['key' => 'OLD-1', 'status' => 'Old Status']],
            'ticket_count'     => 1,
            'captured_at'      => now()->subDays(31),
        ]);

        $response = $this->withToken($token)->getJson('/v1/statuses');
        $response->assertOk();
        $this->assertNotContains('Old Status', $response->json('statuses'));
    }

    public function test_returns_profile_cached_statuses_when_available(): void
    {
        $user  = $this->makeUser();
        $token = $this->makeToken($user);

        // Profile with cached statuses — should be preferred over snapshot scan
        \App\Models\TrackerProfile::create([
            'user_id'        => $user->id,
            'name'           => 'work',
            'tracker_type'   => 'jira',
            'base_url'       => 'https://a.atlassian.net',
            'auth_method'    => 'cloud',
            'known_statuses' => ['Code Review', 'In Progress'],
            'statuses_cached_at' => now(),
        ]);

        // Snapshot with a different status — must NOT appear because profile cache takes precedence
        $this->makeSnapshot($user, [['key' => 'X-1', 'status' => 'Snapshot Only Status']]);

        $statuses = $this->withToken($token)->getJson('/v1/statuses')
            ->assertOk()
            ->json('statuses');

        $this->assertContains('Code Review', $statuses);
        $this->assertNotContains('Snapshot Only Status', $statuses);
    }

    public function test_filters_null_and_empty_statuses(): void
    {
        $user  = $this->makeUser();
        $token = $this->makeToken($user);

        $this->makeSnapshot($user, [
            ['key' => 'PROJ-1', 'status' => 'In Progress'],
            ['key' => 'PROJ-2', 'status' => null],
            ['key' => 'PROJ-3'],              // no status key
        ]);

        $response = $this->withToken($token)->getJson('/v1/statuses');
        $statuses = $response->assertOk()->json('statuses');

        $this->assertEquals(['In Progress'], $statuses);
    }
}
