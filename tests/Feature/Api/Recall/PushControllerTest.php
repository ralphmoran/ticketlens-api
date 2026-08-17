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

    public function test_an_external_id_not_shaped_like_a_cli_generated_id_is_rejected(): void
    {
        // RecallSecretScanner exempts external_id from its entropy heuristic on
        // the assumption it's always a system-generated id, never user-authored
        // free text. That assumption must be server-enforced here, not just
        // true by CLI convention — otherwise a caller bypassing the CLI could
        // smuggle an arbitrary high-entropy string through the one field the
        // scanner no longer checks for randomness.
        [, $token] = $this->makeEntitledUserWithToken();

        $this->withToken($token)
            ->postJson('/v1/recall/push', $this->validPayload(['external_id' => 'not-the-generated-shape']))
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

    // ---- backlog 1c hardening: invalid UTF-8 must 422, not crash the scanner ----

    public function test_invalid_utf8_in_body_via_a_non_json_content_type_returns_422_not_500(): void
    {
        // json_decode() rejects invalid UTF-8 before this route is ever reached
        // for a JSON request, so postJson() can't exercise this path — but the
        // route accepts form-encoded bodies just as readily, and those arrive
        // via $_POST with no UTF-8 gate at all. RecallSecretScanner's whitespace
        // regexes run in /u mode (backlog 1c fix) and return false on invalid
        // UTF-8, which used to reach array_filter() as an uncaught TypeError
        // (500) instead of a validation error. Security review caught this as
        // a regression introduced by the 1c fix itself.
        [, $token] = $this->makeEntitledUserWithToken();

        // postJson() can't send this payload at all — json_encode() itself
        // rejects invalid UTF-8 — so this uses a form-encoded body (post(),
        // not postJson()) with an explicit Accept header, matching how a real
        // API client identifies itself while still using a non-JSON transport.
        $this->withToken($token)
            ->withHeaders(['Accept' => 'application/json'])
            ->post('/v1/recall/push', $this->validPayload(['body' => "AKIA\xFF\xFEIOSFODNN7EXAMPLE"]))
            ->assertStatus(422);
        $this->assertSame(0, RecallNote::count());
    }

    // ---- explicit team targeting ----

    public function test_an_explicit_group_id_targets_that_membership_even_when_the_user_owns_a_different_group(): void
    {
        $owner       = User::factory()->create(['is_owner' => true]);
        $user        = User::factory()->create(['tier' => 'pro']);
        $ownedByUser = Group::create(['name' => 'Owned', 'owner_id' => $user->id]);
        $joined      = Group::create(['name' => "Team Manager's Team", 'owner_id' => $owner->id]);
        $joined->users()->attach($user->id);
        $this->grantRecall($user, $owner);
        $plaintext = 'tl_' . str_repeat('e', 40);
        CliToken::create(['user_id' => $user->id, 'name' => 'CLI Token', 'token_hash' => CliToken::hashToken($plaintext)]);

        $this->withToken($plaintext)
            ->postJson('/v1/recall/push', $this->validPayload(['group_id' => $joined->id]))
            ->assertStatus(200);

        $this->assertSame($joined->id, RecallNote::first()->group_id);
        $this->assertNotSame($ownedByUser->id, RecallNote::first()->group_id);
    }

    public function test_a_null_group_id_falls_back_to_default_resolution_not_a_422(): void
    {
        $owner       = User::factory()->create(['is_owner' => true]);
        $user        = User::factory()->create(['tier' => 'pro']);
        $ownedByUser = Group::create(['name' => 'Owned', 'owner_id' => $user->id]);
        $joined      = Group::create(['name' => "Team Manager's Team", 'owner_id' => $owner->id]);
        $joined->users()->attach($user->id);
        $this->grantRecall($user, $owner);
        $plaintext = 'tl_' . str_repeat('f', 40);
        CliToken::create(['user_id' => $user->id, 'name' => 'CLI Token', 'token_hash' => CliToken::hashToken($plaintext)]);

        $this->withToken($plaintext)
            ->postJson('/v1/recall/push', $this->validPayload(['group_id' => null]))
            ->assertStatus(200);

        $this->assertSame($ownedByUser->id, RecallNote::first()->group_id);
    }

    public function test_a_null_group_id_with_no_group_at_all_returns_403_not_422(): void
    {
        [$user, $token, $group] = $this->makeEntitledUserWithToken();
        // makeEntitledUserWithToken attaches the user to $group as a member,
        // not an owner — remove that membership so this user has NO group at all.
        $group->users()->detach($user->id);

        $this->withToken($token)
            ->postJson('/v1/recall/push', $this->validPayload(['group_id' => null]))
            ->assertStatus(403)
            ->assertJson(['error' => 'No team found']);
    }

    public function test_an_explicit_group_id_the_user_is_not_a_member_of_returns_422_unknown_team_and_persists_nothing(): void
    {
        [, $token] = $this->makeEntitledUserWithToken();
        $foreignGroup = Group::create(['name' => 'Foreign Team', 'owner_id' => User::factory()->create()->id]);

        $this->withToken($token)
            ->postJson('/v1/recall/push', $this->validPayload(['group_id' => $foreignGroup->id]))
            ->assertStatus(422)
            ->assertJson(['error' => 'Unknown team']);

        $this->assertSame(0, RecallNote::count());
    }

    public function test_a_group_id_that_does_not_exist_at_all_returns_422_unknown_team(): void
    {
        [, $token] = $this->makeEntitledUserWithToken();

        $this->withToken($token)
            ->postJson('/v1/recall/push', $this->validPayload(['group_id' => 999999]))
            ->assertStatus(422)
            ->assertJson(['error' => 'Unknown team']);
    }

    public function test_a_non_integer_group_id_returns_422_validation_error(): void
    {
        [, $token] = $this->makeEntitledUserWithToken();

        $this->withToken($token)
            ->postJson('/v1/recall/push', $this->validPayload(['group_id' => 'not-a-number']))
            ->assertStatus(422);
    }

    // ---- captured_at (49g: local-creation vs server-push timestamp) ----

    public function test_captured_at_is_persisted_when_provided(): void
    {
        [, $token] = $this->makeEntitledUserWithToken();

        $this->withToken($token)
            ->postJson('/v1/recall/push', $this->validPayload(['captured_at' => '2026-08-01T12:00:00Z']))
            ->assertStatus(200);

        $this->assertSame('2026-08-01 12:00:00', RecallNote::first()->captured_at->format('Y-m-d H:i:s'));
    }

    public function test_missing_captured_at_is_accepted_not_a_422_older_cli_versions_never_send_it(): void
    {
        [, $token] = $this->makeEntitledUserWithToken();

        $this->withToken($token)->postJson('/v1/recall/push', $this->validPayload())->assertStatus(200);

        $this->assertNull(RecallNote::first()->captured_at);
    }

    public function test_an_unparseable_captured_at_value_returns_422_and_nothing_is_persisted(): void
    {
        [, $token] = $this->makeEntitledUserWithToken();

        $this->withToken($token)
            ->postJson('/v1/recall/push', $this->validPayload(['captured_at' => 'not-a-date']))
            ->assertStatus(422);
        $this->assertSame(0, RecallNote::count());
    }

    public function test_captured_at_updates_on_repush_same_as_other_fields(): void
    {
        [, $token] = $this->makeEntitledUserWithToken();

        $this->withToken($token)->postJson('/v1/recall/push', $this->validPayload(['captured_at' => '2026-08-01T12:00:00Z']))->assertStatus(200);
        $this->withToken($token)->postJson('/v1/recall/push', $this->validPayload(['captured_at' => '2026-08-02T09:00:00Z']))->assertStatus(200);

        $this->assertSame('2026-08-02 09:00:00', RecallNote::first()->captured_at->format('Y-m-d H:i:s'));
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

    // ---- attachments ----

    private function attachmentPayload(string $filename, string $content): array
    {
        return ['filename' => $filename, 'content' => base64_encode($content)];
    }

    public function test_pushing_with_a_valid_attachment_stores_it_and_reports_the_count(): void
    {
        [, $token] = $this->makeEntitledUserWithToken();

        $response = $this->withToken($token)->postJson('/v1/recall/push', $this->validPayload([
            'attachments' => [$this->attachmentPayload('notes.txt', 'repro steps here')],
        ]));

        $response->assertStatus(200)->assertJson(['attachments' => 1]);
        $this->assertSame(1, RecallNote::first()->attachments()->count());
        $this->assertSame('notes.txt', RecallNote::first()->attachments()->first()->filename);
    }

    public function test_a_secret_inside_a_text_attachment_is_rejected_and_nothing_is_persisted(): void
    {
        [, $token] = $this->makeEntitledUserWithToken();

        $response = $this->withToken($token)->postJson('/v1/recall/push', $this->validPayload([
            'attachments' => [$this->attachmentPayload('log.txt', 'Prod key is AKIAIOSFODNN7EXAMPLE')],
        ]));

        $response->assertStatus(422);
        $this->assertSame(0, RecallNote::count());
    }

    public function test_invalid_base64_in_an_attachment_returns_422_and_nothing_is_persisted(): void
    {
        [, $token] = $this->makeEntitledUserWithToken();

        $response = $this->withToken($token)->postJson('/v1/recall/push', $this->validPayload([
            'attachments' => [['filename' => 'a.txt', 'content' => '***not-base64***']],
        ]));

        $response->assertStatus(422);
        $this->assertSame(0, RecallNote::count());
    }

    public function test_an_oversized_attachment_returns_422_and_nothing_is_persisted(): void
    {
        [, $token] = $this->makeEntitledUserWithToken();
        $previous = ini_set('memory_limit', '512M');

        try {
            $response = $this->withToken($token)->postJson('/v1/recall/push', $this->validPayload([
                'attachments' => [$this->attachmentPayload('big.bin', str_repeat('a', 11 * 1024 * 1024))],
            ]));
        } finally {
            ini_set('memory_limit', $previous);
        }

        $response->assertStatus(422);
        $this->assertSame(0, RecallNote::count());
    }

    public function test_a_repush_replaces_the_prior_attachment_set(): void
    {
        [, $token] = $this->makeEntitledUserWithToken();

        $this->withToken($token)->postJson('/v1/recall/push', $this->validPayload([
            'attachments' => [$this->attachmentPayload('first.txt', 'v1')],
        ]))->assertStatus(200);

        $this->withToken($token)->postJson('/v1/recall/push', $this->validPayload([
            'attachments' => [$this->attachmentPayload('second.txt', 'v2')],
        ]))->assertStatus(200);

        $attachments = RecallNote::first()->attachments;
        $this->assertCount(1, $attachments);
        $this->assertSame('second.txt', $attachments->first()->filename);
    }

    public function test_a_disk_write_failure_during_attachment_storage_is_a_typed_500_not_an_uncaught_exception(): void
    {
        [, $token] = $this->makeEntitledUserWithToken();
        \Illuminate\Support\Facades\Storage::shouldReceive('disk')->with('local')->andReturnSelf();
        \Illuminate\Support\Facades\Storage::shouldReceive('put')->andReturn(false);
        \Illuminate\Support\Facades\Storage::shouldReceive('delete')->andReturn(true);

        $response = $this->withToken($token)->postJson('/v1/recall/push', $this->validPayload([
            'attachments' => [$this->attachmentPayload('a.txt', 'hello')],
        ]));

        $response->assertStatus(500)->assertJson(['pushed' => true]);
        // The note itself was saved despite the attachment storage failure —
        // pushed:true above is accurate, not a lie.
        $this->assertSame(1, RecallNote::count());
    }

    public function test_pushing_without_attachments_still_works_unchanged(): void
    {
        [, $token] = $this->makeEntitledUserWithToken();

        $response = $this->withToken($token)->postJson('/v1/recall/push', $this->validPayload());

        $response->assertStatus(200)->assertJson(['attachments' => 0]);
        $this->assertSame(0, RecallNote::first()->attachments()->count());
    }
}
