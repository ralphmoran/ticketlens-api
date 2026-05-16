<?php

namespace Tests\Feature\Console;

use App\Models\CliToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CliAuthTest extends TestCase
{
    use RefreshDatabase;

    // ── show ────────────────────────────────────────────────────────────────

    public function test_show_redirects_guest_to_login(): void
    {
        $this->get('/console/auth/cli?port=55000&state=deadbeef1234567890abcdef12345678&hostname=dev-laptop')
            ->assertRedirect('/console/login');
    }

    public function test_show_renders_authorize_view(): void
    {
        $user = User::factory()->create(['name' => 'Ralph']);

        $this->actingAs($user)
            ->get('/console/auth/cli?port=55000&state=deadbeef1234567890abcdef12345678&hostname=dev-laptop')
            ->assertStatus(200)
            ->assertViewIs('console.cli-authorize')
            ->assertViewHas('port', 55000)
            ->assertViewHas('state', 'deadbeef1234567890abcdef12345678')
            ->assertViewHas('hostname', 'dev-laptop')
            ->assertViewHas('userName', 'Ralph');
    }

    public function test_show_returns_400_when_port_missing(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/console/auth/cli?state=deadbeef1234567890abcdef12345678')
            ->assertStatus(400);
    }

    public function test_show_returns_400_when_state_missing(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/console/auth/cli?port=55000')
            ->assertStatus(400);
    }

    public function test_show_returns_400_when_port_out_of_range(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/console/auth/cli?port=80&state=deadbeef1234567890abcdef12345678')
            ->assertStatus(400);
    }

    public function test_show_allows_missing_hostname(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/console/auth/cli?port=55000&state=deadbeef1234567890abcdef12345678')
            ->assertStatus(200)
            ->assertViewHas('hostname', null);
    }

    // ── authorize ───────────────────────────────────────────────────────────

    public function test_authorize_redirects_guest_to_login(): void
    {
        $this->post('/console/auth/cli', [
            'port'  => 55321,
            'state' => 'deadbeef1234567890abcdef12345678',
        ])->assertRedirect('/console/login');
    }

    public function test_authorize_generates_token_and_redirects_to_localhost(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/console/auth/cli', [
            'port'     => 55321,
            'state'    => 'deadbeef1234567890abcdef12345678',
            'hostname' => 'dev-laptop',
        ]);

        $this->assertDatabaseCount('cli_tokens', 1);

        $token = CliToken::first();
        $this->assertEquals($user->id, $token->user_id);
        $this->assertEquals('CLI (Browser Login)', $token->name);

        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('http://localhost:55321/callback?token=tl_', $location);
        $this->assertStringContainsString('state=deadbeef1234567890abcdef12345678', $location);
    }

    public function test_authorize_revokes_existing_token_before_generating(): void
    {
        $user = User::factory()->create();
        CliToken::create([
            'user_id'    => $user->id,
            'name'       => 'CLI Token',
            'token_hash' => CliToken::hashToken('tl_' . str_repeat('x', 40)),
        ]);

        $this->actingAs($user)->post('/console/auth/cli', [
            'port'  => 55321,
            'state' => 'deadbeef1234567890abcdef12345678',
        ]);

        $this->assertDatabaseCount('cli_tokens', 1);
        $this->assertEquals('CLI (Browser Login)', CliToken::first()->name);
    }

    public function test_authorize_only_revokes_own_token(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        CliToken::create([
            'user_id'    => $user2->id,
            'name'       => 'CLI Token',
            'token_hash' => CliToken::hashToken('tl_' . str_repeat('y', 40)),
        ]);

        $this->actingAs($user1)->post('/console/auth/cli', [
            'port'  => 55321,
            'state' => 'deadbeef1234567890abcdef12345678',
        ]);

        $this->assertDatabaseCount('cli_tokens', 2);
        $this->assertDatabaseHas('cli_tokens', ['user_id' => $user2->id]);
        $this->assertDatabaseHas('cli_tokens', ['user_id' => $user1->id]);
    }

    public function test_authorize_validates_port_is_integer(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/console/auth/cli', [
            'port'  => 'not-a-port',
            'state' => 'deadbeef1234567890abcdef12345678',
        ])->assertSessionHasErrors('port');
    }

    public function test_authorize_validates_state_is_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/console/auth/cli', [
            'port' => 55321,
        ])->assertSessionHasErrors('state');
    }
}
