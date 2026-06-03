<?php

namespace Tests\Feature\Api;

use App\Models\CliToken;
use App\Models\Group;
use App\Models\TrackerProfile;
use App\Models\User;
use App\Models\WorkflowRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileSyncTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::factory()->create(['tier' => 'free', 'permissions' => 0]);
    }

    private function makeToken(User $user): string
    {
        $plaintext = 'tl_' . str_repeat('a', 40);
        CliToken::create([
            'user_id'    => $user->id,
            'name'       => 'CLI Token',
            'token_hash' => CliToken::hashToken($plaintext),
        ]);
        return $plaintext;
    }

    // ── Lock test: this endpoint never exposes credentials (SECURITY INVARIANT) ─

    public function test_it_never_exposes_credential_fields(): void
    {
        $user  = $this->makeUser();
        $token = $this->makeToken($user);

        TrackerProfile::create([
            'user_id' => $user->id, 'name' => 'work', 'tracker_type' => 'jira',
            'base_url' => 'https://acme.atlassian.net', 'auth_method' => 'cloud',
            'email' => 'me@acme.com',
        ]);

        $response = $this->withToken($token)->getJson('/v1/profiles');
        $response->assertOk();

        $profile = $response->json('profiles.0');

        // These keys must NEVER appear in any profile object (security invariant)
        $forbidden = ['api_token', 'apiToken', 'token', 'token_hash', 'pat', 'password', 'secret', 'credential'];
        foreach ($forbidden as $key) {
            $this->assertArrayNotHasKey($key, $profile, "Credential key '{$key}' must never appear in the sync response");
        }
    }

    // ── Feature tests ─────────────────────────────────────────────────────────

    public function test_returns_401_without_token(): void
    {
        $this->getJson('/v1/profiles')->assertStatus(401);
    }

    public function test_returns_401_with_invalid_token(): void
    {
        $this->withToken('tl_invalid')->getJson('/v1/profiles')->assertStatus(401);
    }

    public function test_returns_empty_array_when_no_profiles(): void
    {
        $user  = $this->makeUser();
        $token = $this->makeToken($user);

        $response = $this->withToken($token)->getJson('/v1/profiles');
        $response->assertOk()->assertJson(['profiles' => []]);
    }

    public function test_returns_users_profiles_ordered_by_name(): void
    {
        $user  = $this->makeUser();
        $token = $this->makeToken($user);

        TrackerProfile::create([
            'user_id' => $user->id, 'name' => 'zebra', 'tracker_type' => 'jira',
            'base_url' => 'https://z.atlassian.net', 'auth_method' => 'cloud',
        ]);
        TrackerProfile::create([
            'user_id' => $user->id, 'name' => 'alpha', 'tracker_type' => 'github',
            'base_url' => 'https://github.com/acme/repo', 'auth_method' => 'github',
        ]);

        $response = $this->withToken($token)->getJson('/v1/profiles');
        $response->assertOk();
        $profiles = $response->json('profiles');

        $this->assertCount(2, $profiles);
        $this->assertEquals('alpha', $profiles[0]['name']);
        $this->assertEquals('zebra', $profiles[1]['name']);
    }

    public function test_does_not_return_other_users_profiles(): void
    {
        $user  = $this->makeUser();
        $other = $this->makeUser();
        $token = $this->makeToken($user);

        TrackerProfile::create([
            'user_id' => $other->id, 'name' => 'secret', 'tracker_type' => 'jira',
            'base_url' => 'https://other.atlassian.net', 'auth_method' => 'cloud',
        ]);

        $response = $this->withToken($token)->getJson('/v1/profiles');
        $response->assertOk()->assertJson(['profiles' => []]);
    }

    public function test_profile_shape_contains_expected_fields(): void
    {
        $user  = $this->makeUser();
        $token = $this->makeToken($user);

        TrackerProfile::create([
            'user_id'         => $user->id,
            'name'            => 'work',
            'tracker_type'    => 'jira',
            'base_url'        => 'https://acme.atlassian.net',
            'auth_method'     => 'cloud',
            'email'           => 'me@acme.com',
            'ticket_prefixes' => ['PROJ', 'OPS'],
            'triage_statuses' => ['In Progress', 'Code Review'],
        ]);

        $response = $this->withToken($token)->getJson('/v1/profiles');
        $profile  = $response->json('profiles.0');

        $this->assertEquals('work',                      $profile['name']);
        $this->assertEquals('jira',                      $profile['tracker_type']);
        $this->assertEquals('https://acme.atlassian.net',$profile['base_url']);
        $this->assertEquals('cloud',                     $profile['auth_method']);
        $this->assertEquals('me@acme.com',               $profile['email']);
        $this->assertEquals(['PROJ', 'OPS'],             $profile['ticket_prefixes']);
        $this->assertEquals(['In Progress','Code Review'],$profile['triage_statuses']);
    }

    public function test_free_tier_user_can_sync(): void
    {
        $user  = User::factory()->create(['tier' => 'free', 'permissions' => 0]);
        $token = $this->makeToken($user);

        $response = $this->withToken($token)->getJson('/v1/profiles');
        $response->assertOk();
    }

    // ── Stale rule fields ─────────────────────────────────────────────────────

    public function test_profile_includes_stale_rule_known_statuses_and_cached_at_fields(): void
    {
        $user  = $this->makeUser();
        $token = $this->makeToken($user);

        TrackerProfile::create([
            'user_id' => $user->id, 'name' => 'work', 'tracker_type' => 'jira',
            'base_url' => 'https://a.atlassian.net', 'auth_method' => 'cloud',
        ]);

        $profile = $this->withToken($token)->getJson('/v1/profiles')
            ->assertOk()
            ->json('profiles.0');

        $this->assertArrayHasKey('stale_rule', $profile);
        $this->assertArrayHasKey('known_statuses', $profile);
        $this->assertArrayHasKey('statuses_cached_at', $profile);
        $this->assertNull($profile['stale_rule']);
        $this->assertEquals([], $profile['known_statuses']);
    }

    public function test_team_stale_rule_applied_when_profile_has_no_user_override(): void
    {
        $user  = User::factory()->create(['tier' => 'pro', 'permissions' => 2119]);
        $token = $this->makeToken($user);
        $group = Group::create(['name' => 'Acme', 'owner_id' => $user->id]);
        $group->members()->attach($user->id);

        WorkflowRule::create([
            'group_id' => $group->id,
            'type'     => 'stale',
            'config'   => ['stale_days' => 14, 'statuses' => ['In Review']],
            'enabled'  => true,
        ]);

        TrackerProfile::create([
            'user_id' => $user->id, 'name' => 'work', 'tracker_type' => 'jira',
            'base_url' => 'https://a.atlassian.net', 'auth_method' => 'cloud',
        ]);

        $profile = $this->withToken($token)->getJson('/v1/profiles')
            ->assertOk()
            ->json('profiles.0');

        $this->assertEquals(['stale_days' => 14, 'statuses' => ['In Review']], $profile['stale_rule']);
    }

    public function test_user_stale_rule_overrides_team_rule(): void
    {
        $user  = User::factory()->create(['tier' => 'pro', 'permissions' => 2119]);
        $token = $this->makeToken($user);
        $group = Group::create(['name' => 'Acme', 'owner_id' => $user->id]);
        $group->members()->attach($user->id);

        WorkflowRule::create([
            'group_id' => $group->id,
            'type'     => 'stale',
            'config'   => ['stale_days' => 14, 'statuses' => ['Team Status']],
            'enabled'  => true,
        ]);

        TrackerProfile::create([
            'user_id'    => $user->id,
            'name'       => 'work',
            'tracker_type' => 'jira',
            'base_url'   => 'https://a.atlassian.net',
            'auth_method' => 'cloud',
            'stale_rule' => ['stale_days' => 7, 'statuses' => ['User Status']],
        ]);

        $profile = $this->withToken($token)->getJson('/v1/profiles')
            ->assertOk()
            ->json('profiles.0');

        // User-level override wins
        $this->assertEquals(['stale_days' => 7, 'statuses' => ['User Status']], $profile['stale_rule']);
    }

    public function test_disabled_team_rule_is_not_applied_as_fallback(): void
    {
        $user  = User::factory()->create(['tier' => 'pro', 'permissions' => 2119]);
        $token = $this->makeToken($user);
        $group = Group::create(['name' => 'Acme', 'owner_id' => $user->id]);
        $group->members()->attach($user->id);

        WorkflowRule::create([
            'group_id' => $group->id,
            'type'     => 'stale',
            'config'   => ['stale_days' => 14, 'statuses' => ['In Review']],
            'enabled'  => false,   // disabled — must not be served
        ]);

        TrackerProfile::create([
            'user_id' => $user->id, 'name' => 'work', 'tracker_type' => 'jira',
            'base_url' => 'https://a.atlassian.net', 'auth_method' => 'cloud',
        ]);

        $profile = $this->withToken($token)->getJson('/v1/profiles')
            ->assertOk()
            ->json('profiles.0');

        $this->assertNull($profile['stale_rule']);
    }

    public function test_credential_invariant_stale_rule_contains_no_auth_keys(): void
    {
        $user  = User::factory()->create(['tier' => 'pro', 'permissions' => 2119]);
        $token = $this->makeToken($user);
        $group = Group::create(['name' => 'Acme', 'owner_id' => $user->id]);
        $group->members()->attach($user->id);

        WorkflowRule::create([
            'group_id' => $group->id,
            'type'     => 'stale',
            'config'   => ['stale_days' => 7, 'statuses' => ['In Review']],
            'enabled'  => true,
        ]);

        TrackerProfile::create([
            'user_id' => $user->id, 'name' => 'work', 'tracker_type' => 'jira',
            'base_url' => 'https://a.atlassian.net', 'auth_method' => 'cloud',
        ]);

        $profile    = $this->withToken($token)->getJson('/v1/profiles')->json('profiles.0');
        $staleRule  = $profile['stale_rule'] ?? [];

        $forbidden = ['token', 'api_token', 'password', 'secret', 'key', 'credential', 'pat'];
        foreach ($forbidden as $key) {
            $this->assertArrayNotHasKey($key, $staleRule,
                "Credential key '{$key}' must never appear in stale_rule");
        }
    }
}
