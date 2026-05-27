<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Models\CliToken;
use App\Models\DigestSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeUserWithToken(string $tier = 'team'): array
    {
        $permissions = match ($tier) {
            'team', 'enterprise' => Permission::team(),
            'pro'                => Permission::pro(),
            default              => Permission::free(),
        };
        $user      = User::factory()->create(['tier' => $tier, 'permissions' => $permissions]);
        $plaintext = 'tl_' . str_repeat('c', 40) . $user->id;
        CliToken::create([
            'user_id'    => $user->id,
            'name'       => 'CLI Token',
            'token_hash' => CliToken::hashToken($plaintext),
        ]);
        return [$user, $plaintext];
    }

    // ── Permissions ───────────────────────────────────────────────────────────

    public function test_free_tier_user_returns_403_on_store(): void
    {
        [, $token] = $this->makeUserWithToken('free');

        $this->withToken($token)->postJson('/v1/schedule', [
            'email' => 'dev@example.com', 'timezone' => 'UTC', 'deliverAt' => '07:00',
        ])->assertStatus(403);
        $this->assertDatabaseCount('digest_schedules', 0);
    }

    public function test_free_tier_user_returns_403_on_show(): void
    {
        [, $token] = $this->makeUserWithToken('free');
        $this->withToken($token)->getJson('/v1/schedule')->assertStatus(403);
    }

    public function test_free_tier_user_returns_403_on_destroy(): void
    {
        [, $token] = $this->makeUserWithToken('free');
        $this->withToken($token)->deleteJson('/v1/schedule')->assertStatus(403);
    }

    // ── Cross-user isolation ──────────────────────────────────────────────────

    public function test_show_cannot_see_another_users_schedule(): void
    {
        [$userA, $tokenA] = $this->makeUserWithToken();
        [$userB,        ] = $this->makeUserWithToken();

        DigestSchedule::create([
            'user_id'    => $userB->id,
            'email'      => 'b@example.com',
            'timezone'   => 'UTC',
            'deliver_at' => '08:00:00',
        ]);

        // userA has no schedule — should 404, not see userB's
        $this->withToken($tokenA)->getJson('/v1/schedule')->assertStatus(404);
    }

    public function test_destroy_cannot_delete_another_users_schedule(): void
    {
        [$userA, $tokenA] = $this->makeUserWithToken();
        [$userB,        ] = $this->makeUserWithToken();

        DigestSchedule::create([
            'user_id'    => $userB->id,
            'email'      => 'b@example.com',
            'timezone'   => 'UTC',
            'deliver_at' => '08:00:00',
        ]);

        // userA delete should be a no-op — userB's schedule unaffected
        $this->withToken($tokenA)->deleteJson('/v1/schedule')->assertStatus(200);
        $this->assertDatabaseCount('digest_schedules', 1);
        $this->assertDatabaseHas('digest_schedules', ['user_id' => $userB->id]);
    }

    // ── Auth ─────────────────────────────────────────────────────────────────

    public function test_missing_token_returns_401(): void
    {
        $this->postJson('/v1/schedule', ['email' => 'dev@example.com', 'timezone' => 'UTC', 'deliverAt' => '07:00'])
            ->assertStatus(401);
    }

    public function test_invalid_token_returns_401(): void
    {
        $this->withToken('bad-token')->postJson('/v1/schedule', ['email' => 'dev@example.com', 'timezone' => 'UTC', 'deliverAt' => '07:00'])
            ->assertStatus(401);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    public function test_stores_schedule_and_returns_201(): void
    {
        [$user, $token] = $this->makeUserWithToken();

        $response = $this->withToken($token)->postJson('/v1/schedule', [
            'email'     => 'dev@example.com',
            'timezone'  => 'America/New_York',
            'deliverAt' => '07:00',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['scheduled', 'nextDelivery']);
        $response->assertJson(['scheduled' => true]);
        $this->assertDatabaseHas('digest_schedules', [
            'user_id'    => $user->id,
            'email'      => 'dev@example.com',
            'timezone'   => 'America/New_York',
            'deliver_at' => '07:00',
        ]);
    }

    public function test_updates_existing_schedule_on_duplicate_user(): void
    {
        [, $token] = $this->makeUserWithToken();

        $this->withToken($token)->postJson('/v1/schedule', ['email' => 'old@example.com', 'timezone' => 'UTC', 'deliverAt' => '06:00']);
        $this->withToken($token)->postJson('/v1/schedule', ['email' => 'new@example.com', 'timezone' => 'America/New_York', 'deliverAt' => '07:00']);

        $this->assertDatabaseCount('digest_schedules', 1);
        $this->assertDatabaseHas('digest_schedules', ['email' => 'new@example.com']);
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_schedule_for_user(): void
    {
        [$user, $token] = $this->makeUserWithToken();
        DigestSchedule::create([
            'user_id'    => $user->id,
            'email'      => 'dev@example.com',
            'timezone'   => 'UTC',
            'deliver_at' => '07:00:00',
        ]);

        $this->withToken($token)->getJson('/v1/schedule')
            ->assertStatus(200)
            ->assertJsonStructure(['email', 'timezone', 'deliverAt', 'active', 'nextDelivery']);
    }

    public function test_show_returns_404_when_no_schedule(): void
    {
        [, $token] = $this->makeUserWithToken();
        $this->withToken($token)->getJson('/v1/schedule')->assertStatus(404);
    }

    // ── Destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_removes_schedule(): void
    {
        [$user, $token] = $this->makeUserWithToken();
        DigestSchedule::create([
            'user_id'    => $user->id,
            'email'      => 'dev@example.com',
            'timezone'   => 'UTC',
            'deliver_at' => '07:00:00',
        ]);

        $this->withToken($token)->deleteJson('/v1/schedule')
            ->assertStatus(200)
            ->assertJson(['deleted' => true]);
        $this->assertDatabaseCount('digest_schedules', 0);
    }

    // ── Validation ────────────────────────────────────────────────────────────

    public function test_returns_422_for_invalid_timezone(): void
    {
        [, $token] = $this->makeUserWithToken();

        $this->withToken($token)->postJson('/v1/schedule', [
            'email'     => 'dev@example.com',
            'timezone'  => 'Not/ATimezone',
            'deliverAt' => '07:00',
        ])->assertStatus(422)->assertJsonValidationErrors(['timezone']);
    }

    public function test_returns_422_for_invalid_time_format(): void
    {
        [, $token] = $this->makeUserWithToken();

        $this->withToken($token)->postJson('/v1/schedule', [
            'email'     => 'dev@example.com',
            'timezone'  => 'UTC',
            'deliverAt' => '25:00',
        ])->assertStatus(422)->assertJsonValidationErrors(['deliverAt']);
    }
}
