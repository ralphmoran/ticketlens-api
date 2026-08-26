<?php

namespace Tests\Feature\Api;

use App\Models\AiProviderRole;
use App\Models\CliToken;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConsensusControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeUserWithToken(string $tier = 'pro'): array
    {
        $user      = User::factory()->create(['tier' => $tier]);
        $group     = Group::create(['name' => "Team {$user->id}", 'owner_id' => $user->id]);
        $group->members()->attach($user->id);
        $plaintext = 'tl_' . str_repeat('b', 40);
        CliToken::create(['user_id' => $user->id, 'name' => 'CLI Token', 'token_hash' => CliToken::hashToken($plaintext)]);
        return [$user, $group, $plaintext];
    }

    private function attachTwoProviders(User $user, Group $group): AiProviderRole
    {
        $p1 = $group->aiProviderPools()->create([
            'created_by' => $user->id, 'title' => 'A', 'provider_type' => 'openai_compatible',
            'api_key' => 'sk-test-1234567890', 'endpoint' => 'https://api.example.com/p-a', 'model' => 'x', 'enabled' => true,
        ]);
        $p2 = $group->aiProviderPools()->create([
            'created_by' => $user->id, 'title' => 'B', 'provider_type' => 'openai_compatible',
            'api_key' => 'sk-test-1234567890', 'endpoint' => 'https://api.example.com/p-b', 'model' => 'x', 'enabled' => true,
        ]);
        $role = AiProviderRole::factory()->for($user)->create(['kind' => 'consensus', 'label' => 'Consensus']);
        $role->providers()->attach([$p1->id => ['priority' => 1], $p2->id => ['priority' => 2]]);
        return $role;
    }

    public function test_requires_auth(): void
    {
        $this->postJson('/v1/consensus', [])->assertStatus(401);
    }

    public function test_requires_pro_tier(): void
    {
        [, , $token] = $this->makeUserWithToken('free');

        $this->withToken($token)->postJson('/v1/consensus', [
            'ticketKey' => 'PROJ-1', 'diff' => '+x', 'requirements' => ['Must do x'],
        ])->assertStatus(403);
    }

    public function test_returns_422_when_no_consensus_role_configured(): void
    {
        [, , $token] = $this->makeUserWithToken();

        $this->withToken($token)->postJson('/v1/consensus', [
            'ticketKey' => 'PROJ-1', 'diff' => '+x', 'requirements' => ['Must do x'],
        ])->assertStatus(422)->assertJsonFragment(['error' => 'No consensus role configured. Set one up in Console > Admin > AI Providers.']);
    }

    public function test_returns_422_when_fewer_than_two_providers_attached(): void
    {
        [$user, $group, $token] = $this->makeUserWithToken();
        $onlyOne = $group->aiProviderPools()->create([
            'created_by' => $user->id, 'title' => 'Solo', 'provider_type' => 'anthropic',
            'api_key' => 'sk-ant-test1234567890', 'model' => 'claude-sonnet-5', 'enabled' => true,
        ]);
        $role = AiProviderRole::factory()->for($user)->create(['kind' => 'consensus']);
        $role->providers()->attach($onlyOne->id, ['priority' => 1]);

        $this->withToken($token)->postJson('/v1/consensus', [
            'ticketKey' => 'PROJ-1', 'diff' => '+x', 'requirements' => ['Must do x'],
        ])->assertStatus(422);
    }

    public function test_blocks_and_makes_no_api_calls_when_the_diff_looks_like_a_secret(): void
    {
        [$user, $group, $token] = $this->makeUserWithToken();
        $this->attachTwoProviders($user, $group);
        Http::fake();

        $response = $this->withToken($token)->postJson('/v1/consensus', [
            'ticketKey'    => 'PROJ-1',
            'diff'         => '+const AWS_SECRET_ACCESS_KEY = "wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY";',
            'requirements' => ['Must do x'],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('secret', $response->json('error'));
        Http::assertNothingSent();
    }

    public function test_happy_path_returns_reconciled_results(): void
    {
        [$user, $group, $token] = $this->makeUserWithToken();
        $this->attachTwoProviders($user, $group);

        Http::fake(fn() => Http::response(['choices' => [['message' => ['content' => 'Must do x | FOUND']]]], 200));

        $response = $this->withToken($token)->postJson('/v1/consensus', [
            'ticketKey' => 'PROJ-1', 'diff' => '+implements x', 'requirements' => ['Must do x'],
        ]);

        $response->assertOk();
        $this->assertSame('FOUND', $response->json('results.0.status'));
        $this->assertCount(2, $response->json('perAgent'));
    }

    public function test_returns_503_when_fewer_than_two_providers_succeed(): void
    {
        [$user, $group, $token] = $this->makeUserWithToken();
        $this->attachTwoProviders($user, $group);
        Http::fake(fn() => Http::response([], 500));

        $this->withToken($token)->postJson('/v1/consensus', [
            'ticketKey' => 'PROJ-1', 'diff' => '+x', 'requirements' => ['Must do x'],
        ])->assertStatus(503);
    }
}
