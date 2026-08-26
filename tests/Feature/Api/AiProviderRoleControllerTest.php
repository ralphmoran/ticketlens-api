<?php

namespace Tests\Feature\Api;

use App\Models\AiProviderRole;
use App\Models\Group;
use App\Models\User;
use App\Services\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiProviderRoleControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeMember(): array
    {
        $user  = User::factory()->create(['tier' => 'pro', 'permissions' => 4]); // Summarize bit (Permission::Summarize = 4)
        $group = Group::create(['name' => "Team {$user->id}", 'owner_id' => $user->id]);
        $group->members()->attach($user->id);
        return [$user, $group];
    }

    private function makePoolEntry(Group $group, User $creator, string $title = 'Provider'): \App\Models\AiProviderPool
    {
        return $group->aiProviderPools()->create([
            'created_by' => $creator->id, 'title' => $title, 'provider_type' => 'anthropic',
            'api_key' => 'sk-ant-test1234567890', 'model' => 'claude-sonnet-5',
        ]);
    }

    public function test_store_creates_a_custom_role_by_default(): void
    {
        [$user] = $this->makeMember();

        $response = $this->actingAs($user)->postJson('/console/admin/ai-provider-roles', ['label' => 'QA agent']);

        $response->assertStatus(201)->assertJson(['label' => 'QA agent', 'kind' => 'custom']);
    }

    public function test_only_one_consensus_role_allowed_per_user(): void
    {
        [$user] = $this->makeMember();
        AiProviderRole::factory()->for($user)->create(['kind' => 'consensus', 'label' => 'Consensus']);

        $this->actingAs($user)->postJson('/console/admin/ai-provider-roles', [
            'label' => 'Second consensus', 'kind' => 'consensus',
        ])->assertStatus(422);
    }

    public function test_sync_providers_sets_priority_from_array_order_and_ignores_foreign_pool_ids(): void
    {
        [$user, $group] = $this->makeMember();
        $ownEntry   = $this->makePoolEntry($group, $user, 'Own entry');
        $otherGroup = Group::create(['name' => 'Other', 'owner_id' => User::factory()->create()->id]);
        $foreignEntry = $this->makePoolEntry($otherGroup, $otherGroup->owner, 'Foreign entry');
        $role = AiProviderRole::factory()->for($user)->create();

        $response = $this->actingAs($user)->putJson("/console/admin/ai-provider-roles/{$role->id}/providers", [
            'provider_ids' => [$foreignEntry->id, $ownEntry->id],
        ]);

        $response->assertOk();
        $providers = $response->json('providers');
        $this->assertCount(1, $providers, 'the foreign-group pool id must be silently dropped, not attached');
        $this->assertSame('Own entry', $providers[0]['title']);
        $this->assertSame(1, $providers[0]['priority']);
    }

    public function test_user_a_cannot_sync_user_b_role(): void
    {
        [$userA] = $this->makeMember();
        [$userB, $groupB] = $this->makeMember();
        $roleB = AiProviderRole::factory()->for($userB)->create();

        $this->actingAs($userA)->putJson("/console/admin/ai-provider-roles/{$roleB->id}/providers", [
            'provider_ids' => [],
        ])->assertStatus(404);
    }

    public function test_generate_prompt_uses_the_legacy_ai_service_and_stores_the_result(): void
    {
        [$user] = $this->makeMember();
        \App\Models\UserAiProvider::factory()->for($user)->create(['provider' => 'groq', 'enabled' => true]);
        $role = AiProviderRole::factory()->for($user)->create(['label' => 'QA agent']);

        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('generateText')->once()->andReturn('You are a meticulous QA reviewer.');
        });

        $response = $this->actingAs($user)->postJson("/console/admin/ai-provider-roles/{$role->id}/generate-prompt");

        $response->assertOk()->assertJson(['generated_prompt' => 'You are a meticulous QA reviewer.']);
        $this->assertNotNull($role->fresh()->prompt_generated_at);
    }

    public function test_generate_prompt_returns_422_and_saves_nothing_when_the_model_returns_an_empty_response(): void
    {
        // Reasoning models (e.g. Groq's openai/gpt-oss-120b) can exhaust their
        // token budget on hidden reasoning and return empty content on a 200 —
        // must surface as a real failure, not a silent "success" with nothing.
        [$user] = $this->makeMember();
        \App\Models\UserAiProvider::factory()->for($user)->create(['provider' => 'groq', 'enabled' => true]);
        $role = AiProviderRole::factory()->for($user)->create(['label' => 'QA agent']);

        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('generateText')->once()->andReturn('   ');
        });

        $response = $this->actingAs($user)->postJson("/console/admin/ai-provider-roles/{$role->id}/generate-prompt");

        $response->assertStatus(422);
        $this->assertNull($role->fresh()->generated_prompt);
        $this->assertNull($role->fresh()->prompt_generated_at);
    }

    public function test_generate_prompt_requests_a_larger_token_budget_than_the_summarize_default(): void
    {
        [$user] = $this->makeMember();
        \App\Models\UserAiProvider::factory()->for($user)->create(['provider' => 'groq', 'enabled' => true]);
        $role = AiProviderRole::factory()->for($user)->create(['label' => 'QA agent']);

        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('generateText')
                ->once()
                ->withArgs(fn($user, $prompt, $maxTokens) => $maxTokens === 1024)
                ->andReturn('A real prompt.');
        });

        $this->actingAs($user)->postJson("/console/admin/ai-provider-roles/{$role->id}/generate-prompt")
            ->assertOk();
    }

    public function test_generate_prompt_returns_422_with_no_bootstrap_provider_configured(): void
    {
        [$user] = $this->makeMember();
        $role = AiProviderRole::factory()->for($user)->create(['label' => 'QA agent']);

        $this->actingAs($user)->postJson("/console/admin/ai-provider-roles/{$role->id}/generate-prompt")
            ->assertStatus(422);
    }

    public function test_index_requires_auth(): void
    {
        $this->getJson('/console/admin/ai-provider-roles')->assertStatus(401);
    }
}
