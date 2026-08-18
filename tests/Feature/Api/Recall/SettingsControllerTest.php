<?php

namespace Tests\Feature\Api\Recall;

use App\Models\CliToken;
use App\Models\Group;
use App\Models\RecallSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $tier = 'pro'): User
    {
        return User::factory()->create(['tier' => $tier, 'permissions' => 2119]);
    }

    private function makeToken(User $user): string
    {
        $plaintext = 'tl_' . str_repeat('t', 40);
        CliToken::create([
            'user_id'    => $user->id,
            'name'       => 'Test Token',
            'token_hash' => CliToken::hashToken($plaintext),
        ]);
        return $plaintext;
    }

    private function makeGroupFor(User $user): Group
    {
        $group = Group::create(['name' => "acme-team-{$user->id}", 'owner_id' => $user->id]);
        $group->members()->attach($user->id);
        return $group;
    }

    // ── Auth guard ────────────────────────────────────────────────────────────

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/v1/recall/settings')->assertStatus(401);
    }

    // ── Tier gate ─────────────────────────────────────────────────────────────

    public function test_free_tier_returns_403(): void
    {
        $user  = User::factory()->create(['tier' => 'free', 'permissions' => 0]);
        $token = $this->makeToken($user);

        $this->withToken($token)->getJson('/v1/recall/settings')->assertStatus(403);
    }

    // ── Solo user — platform defaults, not a 404 ─────────────────────────────

    public function test_pro_user_with_no_group_gets_platform_defaults_not_a_404(): void
    {
        $user  = $this->makeUser('pro');
        $token = $this->makeToken($user);

        $this->withToken($token)
            ->getJson('/v1/recall/settings')
            ->assertOk()
            ->assertJson([
                ...RecallSettings::DEFAULTS,
                'is_override' => false,
            ]);
    }

    public function test_pro_user_with_a_group_but_no_override_row_gets_platform_defaults(): void
    {
        $user  = $this->makeUser('pro');
        $token = $this->makeToken($user);
        $this->makeGroupFor($user);

        $this->withToken($token)
            ->getJson('/v1/recall/settings')
            ->assertOk()
            ->assertJson([
                ...RecallSettings::DEFAULTS,
                'is_override' => false,
            ]);
    }

    // ── Manager-set team override ────────────────────────────────────────────

    public function test_returns_the_groups_override_row_when_one_exists(): void
    {
        $user  = $this->makeUser('pro');
        $token = $this->makeToken($user);
        $group = $this->makeGroupFor($user);

        RecallSettings::create([
            'group_id'          => $group->id,
            'flush_cooldown_ms' => 300_000,
            'timeout_ms'        => 8_000,
            'max_queue_size'    => 50,
            'max_entry_age_ms'  => 604_800_000,
        ]);

        $this->withToken($token)
            ->getJson('/v1/recall/settings')
            ->assertOk()
            ->assertJson([
                'flush_cooldown_ms' => 300_000,
                'timeout_ms'        => 8_000,
                'max_queue_size'    => 50,
                'max_entry_age_ms'  => 604_800_000,
                'is_override'       => true,
            ]);
    }

    // ── Backlog #20: recall_strictness flows through the same override row ──

    public function test_returns_the_groups_recall_strictness_override_when_set(): void
    {
        $user  = $this->makeUser('pro');
        $token = $this->makeToken($user);
        $group = $this->makeGroupFor($user);

        RecallSettings::create([
            'group_id'          => $group->id,
            'flush_cooldown_ms' => 300_000,
            'timeout_ms'        => 8_000,
            'max_queue_size'    => 50,
            'max_entry_age_ms'  => 604_800_000,
            'recall_strictness' => 'loose',
        ]);

        $this->withToken($token)
            ->getJson('/v1/recall/settings')
            ->assertOk()
            ->assertJson(['recall_strictness' => 'loose', 'is_override' => true]);
    }

    public function test_an_override_row_predating_this_column_returns_null_not_balanced(): void
    {
        // A row created before this column existed (or that only ever set the
        // queue-settings fields) has no recall_strictness of its own — the
        // controller must not silently substitute 'balanced' here. The CLI's
        // own clamp() is what falls back to the platform default; the API
        // reports what is actually stored, same as it does for `is_override`.
        $user  = $this->makeUser('pro');
        $token = $this->makeToken($user);
        $group = $this->makeGroupFor($user);

        RecallSettings::create([
            'group_id'          => $group->id,
            'flush_cooldown_ms' => 300_000,
            'timeout_ms'        => 8_000,
            'max_queue_size'    => 50,
            'max_entry_age_ms'  => 604_800_000,
        ]);

        $this->withToken($token)
            ->getJson('/v1/recall/settings')
            ->assertOk()
            ->assertJson(['recall_strictness' => null, 'is_override' => true]);
    }

    public function test_another_groups_override_is_never_returned_idor(): void
    {
        $userA  = $this->makeUser('pro');
        $tokenA = $this->makeToken($userA);
        $this->makeGroupFor($userA);

        $userB  = $this->makeUser('pro');
        $groupB = $this->makeGroupFor($userB);
        RecallSettings::create([
            'group_id' => $groupB->id, 'flush_cooldown_ms' => 60_000, 'timeout_ms' => 1_000,
            'max_queue_size' => 10, 'max_entry_age_ms' => 3_600_000, 'recall_strictness' => 'strict',
        ]);

        $this->withToken($tokenA)
            ->getJson('/v1/recall/settings')
            ->assertOk()
            ->assertJson(['is_override' => false, ...RecallSettings::DEFAULTS]);
    }

    // ── Red-team pass (Scenario B: API read path) ───────────────────────────

    public function test_attack_rate_limit_actually_fires_not_just_configured(): void
    {
        $user  = $this->makeUser('pro');
        $token = $this->makeToken($user);

        // RateLimiter::for('recall-settings', ...) allows 30/min by bearer token.
        for ($i = 0; $i < 30; $i++) {
            $this->withToken($token)->getJson('/v1/recall/settings')->assertOk();
        }
        $this->withToken($token)->getJson('/v1/recall/settings')->assertStatus(429);
    }

    // ── Owner bypass ──────────────────────────────────────────────────────────

    public function test_owner_bypasses_pro_tier_gate(): void
    {
        $owner = User::factory()->create(['tier' => 'owner', 'is_owner' => true, 'permissions' => 0]);
        $token = $this->makeToken($owner);

        $this->withToken($token)
            ->getJson('/v1/recall/settings')
            ->assertOk()
            ->assertJsonStructure(['flush_cooldown_ms', 'timeout_ms', 'max_queue_size', 'max_entry_age_ms', 'recall_strictness', 'is_override']);
    }
}
