<?php

namespace Tests\Feature\Api;

use App\Models\CliToken;
use App\Models\Group;
use App\Models\TeamJiraConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamJiraConfigTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeUser(string $tier = 'pro'): User
    {
        return User::factory()->create(['tier' => $tier, 'permissions' => 2119]);
    }

    private function makeToken(User $user): string
    {
        $plaintext = 'tl_' . str_repeat('t', 40);
        CliToken::create([
            'user_id'    => $user->id,
            'name'       => 'Test Token',
            'token_hash' => CliToken::hashToken($plaintext),
        ]);
        return $plaintext;
    }

    private function makeGroupFor(User $user): Group
    {
        $group = Group::create(['name' => "acme-team-{$user->id}", 'owner_id' => $user->id]);
        $group->members()->attach($user->id);
        return $group;
    }

    // ── Auth guard (existing auth.cli middleware behavior) ───────────────────

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/v1/team/config')->assertStatus(401);
    }

    public function test_invalid_token_returns_401(): void
    {
        $this->withToken('tl_invalid_token')->getJson('/v1/team/config')->assertStatus(401);
    }

    // ── Tier gate — lock invariant #1 ────────────────────────────────────────

    public function test_free_tier_returns_403_with_json_error(): void
    {
        $user  = User::factory()->create(['tier' => 'free', 'permissions' => 0]);
        $token = $this->makeToken($user);

        $this->withToken($token)
            ->getJson('/v1/team/config')
            ->assertStatus(403)
            ->assertJsonStructure(['error']);
    }

    // ── Group gate — lock invariant #3 ───────────────────────────────────────

    public function test_pro_user_with_no_group_returns_404(): void
    {
        $user  = $this->makeUser('pro');
        $token = $this->makeToken($user);

        $this->withToken($token)
            ->getJson('/v1/team/config')
            ->assertStatus(404);
    }

    // ── No config row ─────────────────────────────────────────────────────────

    public function test_pro_user_with_group_but_no_config_row_returns_404(): void
    {
        $user  = $this->makeUser('pro');
        $token = $this->makeToken($user);
        $this->makeGroupFor($user);

        $this->withToken($token)
            ->getJson('/v1/team/config')
            ->assertStatus(404);
    }

    // ── Happy path ────────────────────────────────────────────────────────────

    public function test_returns_team_jira_config_for_pro_user(): void
    {
        $user  = $this->makeUser('pro');
        $token = $this->makeToken($user);
        $group = $this->makeGroupFor($user);

        TeamJiraConfig::create([
            'group_id'      => $group->id,
            'jira_base_url' => 'https://acme.atlassian.net',
            'auth_type'     => 'cloud',
            'prefixes'      => ['PROJ', 'OPS'],
            'project_paths' => ['/code/acme'],
            'triage_statuses' => ['In Progress'],
        ]);

        $this->withToken($token)
            ->getJson('/v1/team/config')
            ->assertOk()
            ->assertJsonStructure(['group_name', 'jira_base_url', 'auth_type', 'prefixes', 'project_paths', 'triage_statuses', 'updated_at']);
    }

    // ── Owner bypass — lock invariant #1 (owner skips tier gate) ────────────

    public function test_owner_bypasses_pro_tier_gate(): void
    {
        $owner = User::factory()->create(['tier' => 'owner', 'is_owner' => true, 'permissions' => 0]);
        $token = $this->makeToken($owner);
        $group = $this->makeGroupFor($owner);

        TeamJiraConfig::create([
            'group_id'      => $group->id,
            'jira_base_url' => 'https://owner-org.atlassian.net',
            'auth_type'     => 'cloud',
        ]);

        $this->withToken($token)
            ->getJson('/v1/team/config')
            ->assertOk()
            ->assertJsonStructure(['group_name', 'jira_base_url', 'auth_type']);
    }

    public function test_returns_config_with_null_optional_fields(): void
    {
        $user  = $this->makeUser('pro');
        $token = $this->makeToken($user);
        $group = $this->makeGroupFor($user);

        TeamJiraConfig::create([
            'group_id'      => $group->id,
            'jira_base_url' => 'https://acme.atlassian.net',
            'auth_type'     => 'cloud',
        ]);

        $response = $this->withToken($token)
            ->getJson('/v1/team/config')
            ->assertOk();

        $this->assertNull($response->json('prefixes'));
        $this->assertNull($response->json('project_paths'));
        $this->assertNull($response->json('triage_statuses'));
    }
}
