<?php

namespace Tests\Feature\Console;

use App\Models\CliToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CliTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_creates_token_and_flashes_plaintext(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/console/account/cli-token');
        $response->assertRedirect();

        $this->assertDatabaseCount('cli_tokens', 1);

        $token = CliToken::first();
        $this->assertEquals($user->id, $token->user_id);
        $this->assertEquals(64, strlen($token->token_hash)); // sha256 hex

        // Plaintext must be flashed, never persisted
        $this->assertNotEquals($token->token_hash, $response->getSession()->get('cli_token_generated'));
        $this->assertStringStartsWith('tl_', $response->getSession()->get('cli_token_generated'));
    }

    public function test_generate_revokes_existing_token_first(): void
    {
        $user = User::factory()->create();
        CliToken::create([
            'user_id'    => $user->id,
            'name'       => 'CLI Token',
            'token_hash' => CliToken::hashToken('tl_old_token_value_here_1234567890'),
        ]);

        $this->actingAs($user)->post('/console/account/cli-token');

        // Still only one token — old one was replaced
        $this->assertDatabaseCount('cli_tokens', 1);
    }

    public function test_revoke_removes_token(): void
    {
        $user = User::factory()->create();
        CliToken::create([
            'user_id'    => $user->id,
            'name'       => 'CLI Token',
            'token_hash' => CliToken::hashToken('tl_testtoken1234567890abcdefghijklm'),
        ]);

        $this->actingAs($user)->delete('/console/account/cli-token')->assertRedirect();
        $this->assertDatabaseCount('cli_tokens', 0);
    }

    public function test_revoke_only_removes_own_token(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        CliToken::create([
            'user_id'    => $user2->id,
            'name'       => 'CLI Token',
            'token_hash' => CliToken::hashToken('tl_user2token1234567890abcdefghijk'),
        ]);

        $this->actingAs($user1)->delete('/console/account/cli-token')->assertRedirect();

        // user2's token untouched
        $this->assertDatabaseCount('cli_tokens', 1);
    }

    public function test_hash_is_deterministic(): void
    {
        $hash1 = CliToken::hashToken('tl_test');
        $hash2 = CliToken::hashToken('tl_test');
        $this->assertEquals($hash1, $hash2);
    }

    public function test_find_by_plaintext_returns_correct_token(): void
    {
        $user      = User::factory()->create();
        $plaintext = 'tl_' . str_repeat('x', 40);

        CliToken::create([
            'user_id'    => $user->id,
            'name'       => 'CLI Token',
            'token_hash' => CliToken::hashToken($plaintext),
        ]);

        $found = CliToken::findByPlaintext($plaintext);
        $this->assertNotNull($found);
        $this->assertEquals($user->id, $found->user_id);
    }

    public function test_find_by_plaintext_returns_null_for_wrong_token(): void
    {
        $this->assertNull(CliToken::findByPlaintext('tl_doesnotexist12345678901234567'));
    }
}
