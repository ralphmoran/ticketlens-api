<?php

namespace Tests\Feature\Middleware;

use App\Models\ApiKey;
use App\Models\CliToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ValidateApiKeyTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/_test/api-key';

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('auth.api-key:recall:read')
            ->get(self::URL, fn (Request $r) => response()->json(['user_id' => $r->user()->id]));
    }

    private function proUser(array $attributes = []): User
    {
        return User::factory()->create($attributes + ['tier' => 'pro']);
    }

    private function issueKey(User $user, array $scopes = ['recall:read'], ?string $revokedAt = null): string
    {
        $plaintext = 'tl_pk_' . bin2hex(random_bytes(20));

        ApiKey::create([
            'user_id'    => $user->id,
            'name'       => 'Integration',
            'key_prefix' => substr($plaintext, 0, ApiKey::PREFIX_LENGTH),
            'key_hash'   => ApiKey::hashToken($plaintext),
            'scopes'     => $scopes,
            'revoked_at' => $revokedAt,
        ]);

        return $plaintext;
    }

    public function test_request_without_bearer_is_unauthorized(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function test_unknown_key_is_unauthorized(): void
    {
        $this->withToken('tl_pk_' . str_repeat('a', 40))->getJson(self::URL)->assertUnauthorized();
    }

    public function test_valid_key_with_scope_authenticates_as_its_owner(): void
    {
        $user = $this->proUser();

        $this->withToken($this->issueKey($user))->getJson(self::URL)
            ->assertOk()
            ->assertJson(['user_id' => $user->id]);
    }

    public function test_successful_request_records_last_used_at(): void
    {
        $user = $this->proUser();

        $this->withToken($this->issueKey($user))->getJson(self::URL)->assertOk();

        $this->assertNotNull(ApiKey::first()->last_used_at);
    }

    public function test_key_of_a_suspended_user_is_unauthorized(): void
    {
        $user = $this->proUser(['suspended_at' => now()]);

        $this->withToken($this->issueKey($user))->getJson(self::URL)->assertUnauthorized();
    }

    public function test_key_of_a_downgraded_user_is_forbidden_at_use_time(): void
    {
        $user = $this->proUser();
        $key = $this->issueKey($user);
        $user->forceFill(['tier' => 'free'])->save(); // tier is deliberately not mass-assignable

        $this->withToken($key)->getJson(self::URL)->assertForbidden();
    }

    public function test_team_tier_key_authenticates(): void
    {
        $user = $this->proUser(['tier' => 'team']);

        $this->withToken($this->issueKey($user))->getJson(self::URL)->assertOk();
    }

    public function test_last_used_at_is_not_rewritten_within_one_minute(): void
    {
        $user = $this->proUser();
        $key = $this->issueKey($user);
        $recent = now()->subSeconds(10)->startOfSecond();
        ApiKey::query()->update(['last_used_at' => $recent]);

        $this->withToken($key)->getJson(self::URL)->assertOk();

        $this->assertTrue(ApiKey::first()->last_used_at->equalTo($recent));
    }

    public function test_stale_last_used_at_is_refreshed(): void
    {
        $user = $this->proUser();
        $key = $this->issueKey($user);
        ApiKey::query()->update(['last_used_at' => now()->subHour()]);

        $this->withToken($key)->getJson(self::URL)->assertOk();

        $this->assertTrue(ApiKey::first()->last_used_at->greaterThan(now()->subMinute()));
    }

    public function test_revoked_key_is_unauthorized(): void
    {
        $user = $this->proUser();

        $this->withToken($this->issueKey($user, revokedAt: now()->toDateTimeString()))
            ->getJson(self::URL)
            ->assertUnauthorized();
    }

    public function test_key_without_required_scope_is_forbidden(): void
    {
        $user = $this->proUser();

        $this->withToken($this->issueKey($user, ['triage:read']))
            ->getJson(self::URL)
            ->assertForbidden();
    }

    public function test_cli_token_cannot_authenticate_on_an_api_key_route(): void
    {
        $user = $this->proUser();
        $plaintext = 'tl_' . bin2hex(random_bytes(20));
        CliToken::create([
            'user_id'    => $user->id,
            'name'       => 'CLI Token',
            'token_hash' => CliToken::hashToken($plaintext),
        ]);

        $this->withToken($plaintext)->getJson(self::URL)->assertUnauthorized();
    }

    public function test_five_failed_attempts_lock_out_even_a_valid_key(): void
    {
        $user = $this->proUser();
        $valid = $this->issueKey($user);

        foreach (range(1, 5) as $i) {
            $this->withToken('tl_pk_' . str_repeat('b', 40))->getJson(self::URL)->assertUnauthorized();
        }

        $this->withToken($valid)->getJson(self::URL)->assertUnauthorized();
    }

    public function test_key_is_stored_hashed_never_as_plaintext(): void
    {
        $user = $this->proUser();
        $plaintext = $this->issueKey($user);

        $row = ApiKey::first();

        $this->assertSame(64, strlen($row->key_hash));
        $this->assertNotSame($plaintext, $row->key_hash);
        $this->assertStringNotContainsString($plaintext, json_encode($row->getAttributes()));
    }
}
