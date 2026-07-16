<?php

namespace Tests\Feature\Api\Recall;

use App\Models\CliToken;
use App\Models\Feature;
use App\Models\Group;
use App\Models\RecallNote;
use App\Models\User;
use App\Models\UserFeatureGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PushControllerTest extends TestCase
{
    use RefreshDatabase;

    // The app enforces a single-owner singleton (User::class booted hook) — every
    // helper that needs an owner must share one, never create a second.
    private function grantRecall(User $user, User $grantedBy): void
    {
        $feature = Feature::firstOrCreate(['name' => 'recall'], ['bit_value' => 4096, 'label' => 'Recall', 'sort_order' => 100]);
        UserFeatureGrant::create(['user_id' => $user->id, 'feature_id' => $feature->id, 'granted_by' => $grantedBy->id]);
    }

    private function makeEntitledUserWithToken(): array
    {
        $owner = User::factory()->create(['is_owner' => true]);
        $group = Group::create(['name' => 'T', 'owner_id' => $owner->id]);
        $user  = User::factory()->create(['tier' => 'pro']);
        $group->users()->attach($user->id);
        $this->grantRecall($user, $owner);

        $plaintext = 'tl_' . str_repeat('a', 40);
        CliToken::create(['user_id' => $user->id, 'name' => 'CLI Token', 'token_hash' => CliToken::hashToken($plaintext)]);

        return [$user, $plaintext, $group];
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'external_id' => '1700000000000-abcdef.md',
            'title'       => 'Retry gotcha',
            'body'        => 'Needs exponential backoff, not a fixed delay.',
            'tickets'     => ['PROD-1'],
            'tags'        => ['bug'],
        ], $overrides);
    }

    // ---- auth ----

    public function test_missing_token_returns_401(): void
    {
        $this->postJson('/v1/recall/push', $this->validPayload())->assertStatus(401);
    }

    // ---- entitlement ----

    public function test_a_user_without_the_recall_grant_gets_403_and_nothing_is_persisted(): void
    {
        $owner = User::factory()->create(['is_owner' => true]);
        $group = Group::create(['name' => 'T', 'owner_id' => $owner->id]);
        $user  = User::factory()->create(['tier' => 'pro']);
        $group->users()->attach($user->id);
        $plaintext = 'tl_' . str_repeat('b', 40);
        CliToken::create(['user_id' => $user->id, 'name' => 'CLI Token', 'token_hash' => CliToken::hashToken($plaintext)]);

        $this->withToken($plaintext)->postJson('/v1/recall/push', $this->validPayload())->assertStatus(403);
        $this->assertSame(0, RecallNote::count());
    }

    public function test_free_tier_user_without_a_grant_gets_403(): void
    {
        $owner = User::factory()->create(['is_owner' => true]);
        $group = Group::create(['name' => 'T', 'owner_id' => $owner->id]);
        $user  = User::factory()->create(['tier' => 'free']);
        $group->users()->attach($user->id);
        $plaintext = 'tl_' . str_repeat('c', 40);
        CliToken::create(['user_id' => $user->id, 'name' => 'CLI Token', 'token_hash' => CliToken::hashToken($plaintext)]);

        $this->withToken($plaintext)->postJson('/v1/recall/push', $this->validPayload())->assertStatus(403);
    }

    public function test_an_entitled_pro_user_via_feature_grant_can_push(): void
    {
        [, $token] = $this->makeEntitledUserWithToken();

        $this->withToken($token)->postJson('/v1/recall/push', $this->validPayload())->assertStatus(200);
        $this->assertSame(1, RecallNote::count());
    }

    public function test_owner_can_push_despite_no_group(): void
    {
        $owner     = User::factory()->create(['tier' => 'owner', 'permissions' => 0, 'is_owner' => true]);
        $group     = Group::create(['name' => 'OwnerGroup', 'owner_id' => $owner->id]);
        $plaintext = 'tl_' . str_repeat('d', 40);
        CliToken::create(['user_id' => $owner->id, 'name' => 'CLI Token', 'token_hash' => CliToken::hashToken($plaintext)]);

        $this->withToken($plaintext)->postJson('/v1/recall/push', $this->validPayload())->assertStatus(200);
    }

    // ---- validation ----

    public function test_missing_title_returns_422(): void
    {
        [, $token] = $this->makeEntitledUserWithToken();

        $this->withToken($token)->postJson('/v1/recall/push', $this->validPayload(['title' => null]))->assertStatus(422);
    }

    public function test_an_excessive_sources_array_is_rejected(): void
    {
        // aliases/tickets/sources had no array-count cap (only tags did) —
        // an unbounded array of near-max-length strings is a payload-size
        // amplification vector even though the whole request already sits
        // behind throttle:recall. Distinct URLs (not a repeated string) so this
        // exercises the array:max rule, not an unrelated secret-scanner heuristic.
        [, $token] = $this->makeEntitledUserWithToken();
        $sources = array_map(fn ($i) => "https://example.com/doc-{$i}", range(1, 21));

        $this->withToken($token)
            ->postJson('/v1/recall/push', $this->validPayload(['sources' => $sources]))
            ->assertStatus(422);
    }

    public function test_a_malformed_ticket_key_in_tickets_is_rejected(): void
    {
        [, $token] = $this->makeEntitledUserWithToken();

        $this->withToken($token)
            ->postJson('/v1/recall/push', $this->validPayload(['tickets' => ['PROD-1', '../../etc/passwd']]))
            ->assertStatus(422);
        $this->assertSame(0, RecallNote::count());
    }

    public function test_a_ticket_key_with_a_digit_in_the_prefix_like_cnv1_2_is_accepted(): void
    {
        // Regression: the tickets.* regex must match the CLI's own
        // TICKET_KEY_PATTERN (/^[A-Z][A-Z0-9]+-\d+$/), which allows a digit in
        // the prefix. A stricter letters-only regex here would silently reject
        // every real push for a project like CNV1 — including this project's
        // own standard smoke-test ticket, CNV1-2.
        [, $token] = $this->makeEntitledUserWithToken();

        $this->withToken($token)
            ->postJson('/v1/recall/push', $this->validPayload(['tickets' => ['CNV1-2']]))
            ->assertStatus(200);
        $this->assertSame(1, RecallNote::count());
    }

    // ---- secret scanning (server-side, defense in depth) ----

    public function test_a_secret_in_the_body_is_rejected_with_422_and_nothing_is_persisted(): void
    {
        [, $token] = $this->makeEntitledUserWithToken();

        $this->withToken($token)
            ->postJson('/v1/recall/push', $this->validPayload(['body' => 'Prod key is AKIAIOSFODNN7EXAMPLE']))
            ->assertStatus(422);
        $this->assertSame(0, RecallNote::count());
    }

    public function test_a_secret_in_a_tag_is_rejected_even_though_the_client_should_have_already_caught_it(): void
    {
        [, $token] = $this->makeEntitledUserWithToken();

        $this->withToken($token)
            ->postJson('/v1/recall/push', $this->validPayload(['tags' => ['AKIAIOSFODNN7EXAMPLE']]))
            ->assertStatus(422);
    }

    // ---- idempotency ----

    public function test_pushing_the_same_external_id_twice_upserts_one_row(): void
    {
        [, $token] = $this->makeEntitledUserWithToken();

        $this->withToken($token)->postJson('/v1/recall/push', $this->validPayload(['title' => 'v1']))->assertStatus(200);
        $this->withToken($token)->postJson('/v1/recall/push', $this->validPayload(['title' => 'v2']))->assertStatus(200);

        $this->assertSame(1, RecallNote::count());
        $this->assertSame('v2', RecallNote::first()->title);
    }
}
