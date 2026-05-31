<?php

namespace Tests\Feature\Api;

use App\Models\BriefTemplate;
use App\Models\CliToken;
use App\Models\Group;
use App\Models\User;
use Database\Seeders\BriefTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BriefTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $tier = 'free'): User
    {
        return User::factory()->create(['tier' => $tier, 'permissions' => 0]);
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

    private function seedSystemTemplates(): void
    {
        (new BriefTemplateSeeder())->run();
    }

    // ── API: GET /v1/templates ──────────────────────────────────────────────

    public function test_returns_401_without_token(): void
    {
        $this->getJson('/v1/templates')->assertStatus(401);
    }

    public function test_returns_system_templates_for_free_user(): void
    {
        $this->seedSystemTemplates();
        $user  = $this->makeUser('free');
        $token = $this->makeToken($user);

        $response = $this->withToken($token)->getJson('/v1/templates');

        $response->assertOk();
        $slugs = collect($response->json())->pluck('slug')->all();
        $this->assertContains('full', $slugs);
        $this->assertContains('quick', $slugs);
        $this->assertContains('code-review', $slugs);
    }

    public function test_system_templates_have_required_fields(): void
    {
        $this->seedSystemTemplates();
        $user  = $this->makeUser('free');
        $token = $this->makeToken($user);

        $response = $this->withToken($token)->getJson('/v1/templates');

        $first = $response->json('0');
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('slug', $first);
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('sections', $first);
        $this->assertArrayHasKey('is_system', $first);
    }

    public function test_does_not_return_templates_from_other_groups(): void
    {
        $this->seedSystemTemplates();
        $user  = $this->makeUser('team');
        $token = $this->makeToken($user);

        // Create a custom template for a different group
        $otherGroup = Group::create(['name' => 'Other Team', 'owner_id' => $user->id]);
        BriefTemplate::create([
            'group_id'  => $otherGroup->id,
            'slug'      => 'private',
            'name'      => 'Private Template',
            'sections'  => ['meta' => true],
            'is_system' => false,
        ]);

        $response = $this->withToken($token)->getJson('/v1/templates');

        $slugs = collect($response->json())->pluck('slug')->all();
        $this->assertNotContains('private', $slugs, 'Templates from other groups must not be returned');
    }
}
