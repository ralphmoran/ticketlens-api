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
            $mock->shouldReceive('generateTextWithUsage')->once()->andReturn([
                'text' => 'You are a meticulous QA reviewer.', 'tokens' => 210, 'provider' => 'groq',
            ]);
        });

        $response = $this->actingAs($user)->postJson("/console/admin/ai-provider-roles/{$role->id}/generate-prompt");

        $response->assertOk()->assertJson(['generated_prompt' => 'You are a meticulous QA reviewer.']);
        $this->assertNotNull($role->fresh()->prompt_generated_at);
    }

    // ── UsageLog recording — real token counts, ticket_key always null ────────

    public function test_records_usage_log_with_real_token_count_and_no_ticket_key(): void
    {
        [$user] = $this->makeMember();
        \App\Models\UserAiProvider::factory()->for($user)->create(['provider' => 'groq', 'enabled' => true]);
        $role = AiProviderRole::factory()->for($user)->create(['label' => 'QA agent']);

        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('generateTextWithUsage')->once()->andReturn([
                'text' => 'A real prompt.', 'tokens' => 210, 'provider' => 'groq',
            ]);
        });

        $this->actingAs($user)->postJson("/console/admin/ai-provider-roles/{$role->id}/generate-prompt");

        $this->assertDatabaseHas('usage_logs', [
            'user_id'     => $user->id,
            'action'      => 'ai_provider_role_generate',
            'ticket_key'  => null,
            'tokens_used' => 210,
            'metadata'    => null,
        ]);
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
            $mock->shouldReceive('generateTextWithUsage')->once()->andReturn(['text' => '   ', 'tokens' => 60, 'provider' => 'groq']);
        });

        $response = $this->actingAs($user)->postJson("/console/admin/ai-provider-roles/{$role->id}/generate-prompt");

        $response->assertStatus(422);
        $this->assertNull($role->fresh()->generated_prompt);
        $this->assertNull($role->fresh()->prompt_generated_at);
        // The provider call succeeded and spent real tokens even though the
        // reasoning budget was exhausted before a visible answer — still logged.
        $this->assertDatabaseHas('usage_logs', ['action' => 'ai_provider_role_generate', 'tokens_used' => 60]);
    }

    public function test_generate_prompt_requests_a_larger_token_budget_than_the_summarize_default(): void
    {
        [$user] = $this->makeMember();
        \App\Models\UserAiProvider::factory()->for($user)->create(['provider' => 'groq', 'enabled' => true]);
        $role = AiProviderRole::factory()->for($user)->create(['label' => 'QA agent']);

        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('generateTextWithUsage')
                ->once()
                ->withArgs(fn($user, $prompt, $maxTokens) => $maxTokens === 1024)
                ->andReturn(['text' => 'A real prompt.', 'tokens' => 210, 'provider' => 'groq']);
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
        $this->assertDatabaseCount('usage_logs', 0);
    }

    public function test_index_requires_auth(): void
    {
        $this->getJson('/console/admin/ai-provider-roles')->assertStatus(401);
    }
}
