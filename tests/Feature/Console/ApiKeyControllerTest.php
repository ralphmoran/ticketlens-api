<?php

namespace Tests\Feature\Console;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ApiKeyControllerTest extends TestCase
{
    use RefreshDatabase;

    private function proUser(): User
    {
        return User::factory()->create(['tier' => 'pro']);
    }

    private function makeKey(User $user, array $attributes = []): ApiKey
    {
        return ApiKey::create($attributes + [
            'user_id'    => $user->id,
            'name'       => 'Existing',
            'key_prefix' => 'tl_pk_' . bin2hex(random_bytes(4)),
            'key_hash'   => ApiKey::hashToken(bin2hex(random_bytes(20))),
            'scopes'     => ['recall:read'],
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + ['name' => 'Zapier', 'scopes' => ['recall:read', 'triage:read']];
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/console/api-keys')->assertRedirect(route('console.login'));
    }

    public function test_index_lists_only_the_users_own_keys_without_secrets(): void
    {
        $user = $this->proUser();
        $this->makeKey($user, ['name' => 'Mine']);
        $this->makeKey(User::factory()->create(), ['name' => 'Theirs']);

        $this->actingAs($user)->get('/console/api-keys')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Console/ApiKeys')
                ->has('keys', 1)
                ->where('keys.0.name', 'Mine')
                ->missing('keys.0.key_hash')
                ->has('availableScopes', count(ApiKey::SCOPES))
                ->where('maxActiveKeys', ApiKey::MAX_ACTIVE_PER_USER),
        );
    }

    public function test_store_creates_a_hashed_key_and_flashes_plaintext_once(): void
    {
        $user = $this->proUser();

        $response = $this->actingAs($user)->post('/console/api-keys', $this->payload());

        $response->assertRedirect();
        $plaintext = $response->getSession()->get('api_key_generated');
        $this->assertStringStartsWith('tl_pk_', $plaintext);

        $key = ApiKey::sole();
        $this->assertSame($user->id, $key->user_id);
        $this->assertSame(['recall:read', 'triage:read'], $key->scopes);
        $this->assertSame(ApiKey::hashToken($plaintext), $key->key_hash);
        $this->assertSame(substr($plaintext, 0, ApiKey::PREFIX_LENGTH), $key->key_prefix);
    }

    public function test_flash_prop_exposes_the_generated_key_to_the_page(): void
    {
        $this->actingAs($this->proUser())
            ->withSession(['api_key_generated' => 'tl_pk_example'])
            ->get('/console/api-keys')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('flash.api_key_generated', 'tl_pk_example'));
    }

    public function test_store_rejects_missing_name_and_scopes(): void
    {
        $this->actingAs($this->proUser())
            ->postJson('/console/api-keys', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'scopes']);
    }

    public function test_store_rejects_unknown_scope(): void
    {
        $this->actingAs($this->proUser())
            ->postJson('/console/api-keys', $this->payload(['scopes' => ['admin:everything']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scopes.0');
    }

    public function test_store_blocks_the_sixth_active_key(): void
    {
        $user = $this->proUser();
        foreach (range(1, ApiKey::MAX_ACTIVE_PER_USER) as $i) {
            $this->makeKey($user);
        }

        $this->actingAs($user)
            ->postJson('/console/api-keys', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->assertSame(ApiKey::MAX_ACTIVE_PER_USER, ApiKey::count());
    }

    public function test_revoked_keys_do_not_count_toward_the_limit(): void
    {
        $user = $this->proUser();
        foreach (range(1, ApiKey::MAX_ACTIVE_PER_USER) as $i) {
            $this->makeKey($user, ['revoked_at' => now()]);
        }

        $this->actingAs($user)->post('/console/api-keys', $this->payload())->assertRedirect();
    }

    public function test_store_is_forbidden_on_the_free_tier(): void
    {
        $this->actingAs(User::factory()->create(['tier' => 'free']))
            ->postJson('/console/api-keys', $this->payload())
            ->assertForbidden();

        $this->assertSame(0, ApiKey::count());
    }

    public function test_destroy_revokes_the_users_own_key(): void
    {
        $user = $this->proUser();
        $key = $this->makeKey($user);

        $this->actingAs($user)->delete("/console/api-keys/{$key->id}")->assertRedirect();

        $this->assertNotNull($key->fresh()->revoked_at);
    }

    public function test_destroy_cannot_revoke_another_users_key(): void
    {
        $key = $this->makeKey(User::factory()->create());

        $this->actingAs($this->proUser())->delete("/console/api-keys/{$key->id}")->assertNotFound();

        $this->assertNull($key->fresh()->revoked_at);
    }
}
