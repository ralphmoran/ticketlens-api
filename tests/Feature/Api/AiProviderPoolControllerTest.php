<?php

namespace Tests\Feature\Api;

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiProviderPoolControllerTest extends TestCase
{
    use RefreshDatabase;

    // team(2687) | teamManagerMask(384) = 3071 — includes TeamManageMembers(128) for EnsureTeamManager
    private function makeManager(): array
    {
        $manager = User::factory()->create(['tier' => 'team', 'permissions' => 3071]);
        $group   = Group::create(['name' => "Team {$manager->id}", 'owner_id' => $manager->id]);
        $group->members()->attach($manager->id);
        return [$manager, $group];
    }

    private function makeMember(Group $group): User
    {
        $member = User::factory()->create(['tier' => 'pro', 'permissions' => 4]); // Summarize bit (Permission::Summarize = 4)
        $group->members()->attach($member->id);
        return $member;
    }

    // ── GET /console/admin/ai-provider-pools ────────────────────────────────

    public function test_index_requires_auth(): void
    {
        $this->get('/console/admin/ai-provider-pools')->assertRedirect('/console/login');
    }

    public function test_index_is_manager_only(): void
    {
        [$manager, $group] = $this->makeManager();
        $member = $this->makeMember($group);

        // EnsureTeamManager redirects non-JSON requests rather than 403ing them.
        $this->actingAs($member)->get('/console/admin/ai-provider-pools')->assertRedirect('/console/dashboard');
        $this->actingAs($manager)->get('/console/admin/ai-provider-pools')->assertOk();
    }

    public function test_index_only_returns_the_managers_own_group_pool(): void
    {
        [$managerA, $groupA] = $this->makeManager();
        $groupB = Group::create(['name' => 'B', 'owner_id' => User::factory()->create()->id]);
        $groupB->aiProviderPools()->create([
            'created_by' => $managerA->id, 'title' => 'Group B provider', 'provider_type' => 'anthropic',
            'api_key' => 'sk-ant-test1234567890', 'model' => 'claude-sonnet-5',
        ]);

        $response = $this->actingAs($managerA)->getJson('/console/admin/ai-provider-pools');

        $response->assertOk()->assertJson(['providers' => []]);
    }

    // ── POST /console/admin/ai-provider-pools ───────────────────────────────

    public function test_store_creates_a_pool_entry(): void
    {
        [$manager] = $this->makeManager();

        $response = $this->actingAs($manager)->postJson('/console/admin/ai-provider-pools', [
            'title'         => 'Codex',
            'provider_type' => 'openai_compatible',
            'api_key'       => 'sk-test-1234567890abcdef',
            'endpoint'      => 'https://api.openai.com/v1/chat/completions',
            'model'         => 'gpt-5.3-codex',
        ]);

        $response->assertStatus(201);
        $this->assertSame('Codex', $response->json('title'));
        $this->assertStringContainsString('*', $response->json('masked_key'));
        $this->assertArrayNotHasKey('api_key', $response->json());
        // Caught live via browser verification: Eloquent's create() doesn't re-sync the
        // migration's DB-level `enabled` default into the returned in-memory instance —
        // toDisplayArray() was showing enabled: null right after creation.
        $this->assertTrue($response->json('enabled'));
    }

    public function test_store_requires_endpoint_for_openai_compatible(): void
    {
        [$manager] = $this->makeManager();

        $this->actingAs($manager)->postJson('/console/admin/ai-provider-pools', [
            'title' => 'Missing endpoint', 'provider_type' => 'openai_compatible',
            'api_key' => 'sk-test-1234567890', 'model' => 'x',
        ])->assertStatus(422);
    }

    public function test_store_allows_anthropic_without_endpoint(): void
    {
        [$manager] = $this->makeManager();

        $this->actingAs($manager)->postJson('/console/admin/ai-provider-pools', [
            'title' => 'Claude Opus', 'provider_type' => 'anthropic',
            'api_key' => 'sk-ant-test1234567890', 'model' => 'claude-opus-5',
        ])->assertStatus(201);
    }

    public function test_member_cannot_store(): void
    {
        [$manager, $group] = $this->makeManager();
        $member = $this->makeMember($group);

        $this->actingAs($member)->postJson('/console/admin/ai-provider-pools', [
            'title' => 'x', 'provider_type' => 'anthropic', 'api_key' => 'sk-ant-1234567890', 'model' => 'x',
        ])->assertStatus(403);
    }

    // ── PUT/DELETE scoping ───────────────────────────────────────────────────

    public function test_manager_a_cannot_update_group_b_pool_entry(): void
    {
        [$managerA] = $this->makeManager();
        $ownerB = User::factory()->create();
        $groupB = Group::create(['name' => 'B', 'owner_id' => $ownerB->id]);
        $entry = $groupB->aiProviderPools()->create([
            'created_by' => $ownerB->id, 'title' => 'B entry', 'provider_type' => 'anthropic',
            'api_key' => 'sk-ant-test1234567890', 'model' => 'claude-sonnet-5',
        ]);

        $this->actingAs($managerA)->putJson("/console/admin/ai-provider-pools/{$entry->id}", ['title' => 'hijacked'])
            ->assertStatus(404);
    }

    public function test_destroy_removes_entry(): void
    {
        [$manager, $group] = $this->makeManager();
        $entry = $group->aiProviderPools()->create([
            'created_by' => $manager->id, 'title' => 'x', 'provider_type' => 'anthropic',
            'api_key' => 'sk-ant-test1234567890', 'model' => 'claude-sonnet-5',
        ]);

        $this->actingAs($manager)->deleteJson("/console/admin/ai-provider-pools/{$entry->id}")
            ->assertOk()->assertJson(['deleted' => true]);
        $this->assertDatabaseMissing('ai_provider_pools', ['id' => $entry->id]);
    }
}
