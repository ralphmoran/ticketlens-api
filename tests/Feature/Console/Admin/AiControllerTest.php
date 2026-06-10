<?php

namespace Tests\Feature\Console\Admin;

use App\Models\CliToken;
use App\Models\Group;
use App\Models\User;
use App\Models\UserAiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeManager(): User
    {
        $manager = User::factory()->create(['tier' => 'team', 'permissions' => 511]);
        $group   = Group::create(['name' => "Team {$manager->id}", 'owner_id' => $manager->id]);
        $group->members()->attach($manager->id);
        return $manager;
    }

    public function test_renders_ai_settings_page(): void
    {
        $manager = $this->makeManager();

        $this->actingAs($manager)
            ->get('/console/admin/ai')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Console/Admin/Ai')
                ->has('providers')
                ->has('supported_providers')
                ->has('cli_token')
            );
    }

    public function test_ai_settings_page_includes_active_cli_token(): void
    {
        $manager = $this->makeManager();
        CliToken::create([
            'user_id'    => $manager->id,
            'name'       => 'CLI Token',
            'token_hash' => CliToken::hashToken('tl_' . str_repeat('a', 40)),
        ]);

        $this->actingAs($manager)
            ->get('/console/admin/ai')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Console/Admin/Ai')
                ->where('cli_token.name', 'CLI Token')
            );
    }

    public function test_ai_settings_page_cli_token_is_null_when_none_exists(): void
    {
        $manager = $this->makeManager();

        $this->actingAs($manager)
            ->get('/console/admin/ai')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Console/Admin/Ai')
                ->where('cli_token', null)
            );
    }

    public function test_lists_user_providers_on_page(): void
    {
        $manager = $this->makeManager();
        UserAiProvider::factory()->for($manager)->create(['provider' => 'groq']);

        $response = $this->actingAs($manager)->get('/console/admin/ai');

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Console/Admin/Ai')
                ->where('providers.0.provider', 'groq')
                ->where('providers.0.masked_key', fn ($key) => str_contains($key, '***'))
            );
    }

    public function test_requires_authentication(): void
    {
        $this->get('/console/admin/ai')->assertRedirect('/console/login');
    }

    public function test_requires_manager_role(): void
    {
        $user = User::factory()->create(['tier' => 'free']);

        $this->actingAs($user)
            ->get('/console/admin/ai')
            ->assertStatus(302); // redirected by team.manager middleware
    }

    public function test_store_creates_provider(): void
    {
        $manager = $this->makeManager();

        $this->actingAs($manager)
            ->postJson('/console/admin/ai-providers', [
                'provider' => 'groq',
                'api_key'  => 'gsk_test1234567890test',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('user_ai_providers', [
            'user_id'  => $manager->id,
            'provider' => 'groq',
        ]);
    }

    public function test_store_returns_masked_key(): void
    {
        $manager = $this->makeManager();

        $response = $this->actingAs($manager)
            ->postJson('/console/admin/ai-providers', [
                'provider' => 'groq',
                'api_key'  => 'gsk_test1234567890test',
            ]);

        $response->assertStatus(201);
        $this->assertStringContainsString('***', $response->json('masked_key'));
        $this->assertArrayNotHasKey('api_key', $response->json());
    }

    public function test_update_changes_enabled_state(): void
    {
        $manager  = $this->makeManager();
        $provider = UserAiProvider::factory()->for($manager)->create([
            'provider' => 'groq',
            'enabled'  => true,
        ]);

        $this->actingAs($manager)
            ->putJson("/console/admin/ai-providers/{$provider->id}", ['enabled' => false])
            ->assertStatus(200);

        $this->assertDatabaseHas('user_ai_providers', [
            'id'      => $provider->id,
            'enabled' => false,
        ]);
    }

    public function test_destroy_removes_provider(): void
    {
        $manager  = $this->makeManager();
        $provider = UserAiProvider::factory()->for($manager)->create(['provider' => 'groq']);

        $this->actingAs($manager)
            ->delete("/console/admin/ai-providers/{$provider->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('user_ai_providers', ['id' => $provider->id]);
    }

    public function test_cannot_modify_another_users_provider(): void
    {
        $manager = $this->makeManager();
        $other   = User::factory()->create();
        $provider = UserAiProvider::factory()->for($other)->create(['provider' => 'groq']);

        $this->actingAs($manager)
            ->delete("/console/admin/ai-providers/{$provider->id}")
            ->assertStatus(404);
    }

    public function test_crud_requires_authentication(): void
    {
        $this->postJson('/console/admin/ai-providers', ['provider' => 'groq', 'api_key' => 'test'])
            ->assertStatus(401);
    }
}
