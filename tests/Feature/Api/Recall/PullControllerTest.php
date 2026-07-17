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

class PullControllerTest extends TestCase
{
    use RefreshDatabase;

    private function grantRecall(User $user, User $grantedBy): void
    {
        $feature = Feature::firstOrCreate(['name' => 'recall'], ['bit_value' => 4096, 'label' => 'Recall', 'sort_order' => 100]);
        UserFeatureGrant::create(['user_id' => $user->id, 'feature_id' => $feature->id, 'granted_by' => $grantedBy->id]);
    }

    private function makeEntitledMember(Group $group, User $grantedBy, string $tokenSeed): array
    {
        $user = User::factory()->create(['tier' => 'pro']);
        $group->users()->attach($user->id);
        $this->grantRecall($user, $grantedBy);
        $plaintext = 'tl_' . str_repeat($tokenSeed, 40);
        CliToken::create(['user_id' => $user->id, 'name' => 'CLI Token', 'token_hash' => CliToken::hashToken($plaintext)]);
        return [$user, $plaintext];
    }

    public function test_missing_token_returns_401(): void
    {
        $this->getJson('/v1/recall/pull')->assertStatus(401);
    }

    public function test_a_user_without_the_recall_grant_gets_403(): void
    {
        $owner = User::factory()->create(['is_owner' => true]);
        $group = Group::create(['name' => 'T', 'owner_id' => $owner->id]);
        $user  = User::factory()->create(['tier' => 'pro']);
        $group->users()->attach($user->id);
        $plaintext = 'tl_' . str_repeat('a', 40);
        CliToken::create(['user_id' => $user->id, 'name' => 'CLI Token', 'token_hash' => CliToken::hashToken($plaintext)]);

        $this->withToken($plaintext)->getJson('/v1/recall/pull')->assertStatus(403);
    }

    // ---- tenant isolation (critical) ----

    public function test_a_user_never_sees_another_groups_notes_under_any_circumstance(): void
    {
        $owner  = User::factory()->create(['is_owner' => true]);
        $groupA = Group::create(['name' => 'A', 'owner_id' => $owner->id]);
        $groupB = Group::create(['name' => 'B', 'owner_id' => User::factory()->create()->id]);

        [$userA, $tokenA] = $this->makeEntitledMember($groupA, $owner, 'a');
        [$userB, $tokenB] = $this->makeEntitledMember($groupB, $owner, 'b');

        RecallNote::create([
            'group_id' => $groupA->id, 'author_id' => $userA->id, 'external_id' => 'a.md',
            'title' => 'Group A secret note', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);
        RecallNote::create([
            'group_id' => $groupB->id, 'author_id' => $userB->id, 'external_id' => 'b.md',
            'title' => 'Group B secret note', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);

        $responseA = $this->withToken($tokenA)->getJson('/v1/recall/pull')->assertStatus(200)->json('notes');
        $this->assertCount(1, $responseA);
        $this->assertSame('Group A secret note', $responseA[0]['title']);

        $responseB = $this->withToken($tokenB)->getJson('/v1/recall/pull')->assertStatus(200)->json('notes');
        $this->assertCount(1, $responseB);
        $this->assertSame('Group B secret note', $responseB[0]['title']);
    }

    public function test_a_client_supplied_group_id_query_param_is_ignored_never_trusted(): void
    {
        $owner  = User::factory()->create(['is_owner' => true]);
        $groupA = Group::create(['name' => 'A', 'owner_id' => $owner->id]);
        $groupB = Group::create(['name' => 'B', 'owner_id' => User::factory()->create()->id]);

        [$userA, $tokenA] = $this->makeEntitledMember($groupA, $owner, 'a');
        $victim = User::factory()->create();
        RecallNote::create([
            'group_id' => $groupB->id, 'author_id' => $victim->id, 'external_id' => 'b.md',
            'title' => 'Group B secret note', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);

        // userA (group A) attempts to read group B's notes via an explicit query param — must be ignored.
        $response = $this->withToken($tokenA)->getJson("/v1/recall/pull?group_id={$groupB->id}")->assertStatus(200)->json('notes');
        $this->assertCount(0, $response);
    }

    // ---- since-delta ----

    public function test_since_param_only_returns_notes_updated_after_it(): void
    {
        $owner = User::factory()->create(['is_owner' => true]);
        $group = Group::create(['name' => 'T', 'owner_id' => $owner->id]);
        [$user, $token] = $this->makeEntitledMember($group, $owner, 'a');

        $old = RecallNote::create([
            'group_id' => $group->id, 'author_id' => $user->id, 'external_id' => 'old.md',
            'title' => 'Old', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);
        RecallNote::where('id', $old->id)->update(['updated_at' => now()->subDays(2)]);
        RecallNote::create([
            'group_id' => $group->id, 'author_id' => $user->id, 'external_id' => 'new.md',
            'title' => 'New', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);

        // urlencode() matters here: an ISO8601 offset like "+00:00" is otherwise
        // decoded as a literal space in a query string, same as any real HTTP client
        // (including the CLI's URLSearchParams) would encode it before sending.
        $since = urlencode(now()->subDay()->toIso8601String());
        $response = $this->withToken($token)->getJson("/v1/recall/pull?since={$since}")->assertStatus(200)->json('notes');
        $this->assertCount(1, $response);
        $this->assertSame('New', $response[0]['title']);
    }

    public function test_a_malformed_since_param_returns_422_not_an_uncaught_exception(): void
    {
        $owner = User::factory()->create(['is_owner' => true]);
        $group = Group::create(['name' => 'T', 'owner_id' => $owner->id]);
        [, $token] = $this->makeEntitledMember($group, $owner, 'a');

        $this->withToken($token)->getJson('/v1/recall/pull?since=not-a-date')->assertStatus(422);
    }

    public function test_no_group_returns_an_empty_list_not_an_error(): void
    {
        // Owner grants recall directly to a user with no group at all.
        $owner = User::factory()->create(['is_owner' => true]);
        $user  = User::factory()->create(['tier' => 'pro']);
        $this->grantRecall($user, $owner);
        $plaintext = 'tl_' . str_repeat('z', 40);
        CliToken::create(['user_id' => $user->id, 'name' => 'CLI Token', 'token_hash' => CliToken::hashToken($plaintext)]);

        $this->withToken($plaintext)->getJson('/v1/recall/pull')->assertStatus(200)->assertJson(['notes' => [], 'deleted' => []]);
    }

    // ---- tombstones (deleted notes) ----

    public function test_response_includes_a_deleted_array_with_external_id_and_tickets_for_removed_notes(): void
    {
        $owner = User::factory()->create(['is_owner' => true]);
        $group = Group::create(['name' => 'T', 'owner_id' => $owner->id]);
        [$user, $token] = $this->makeEntitledMember($group, $owner, 'a');

        $note = RecallNote::create([
            'group_id' => $group->id, 'author_id' => $user->id, 'external_id' => 'gone.md',
            'title' => 'Gone', 'aliases' => [], 'tickets' => ['PROD-1'], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);
        $note->delete();

        $response = $this->withToken($token)->getJson('/v1/recall/pull')->assertStatus(200)->json('deleted');

        $this->assertCount(1, $response);
        $this->assertSame('gone.md', $response[0]['external_id']);
        $this->assertSame(['PROD-1'], $response[0]['tickets']);
    }

    public function test_a_still_live_note_never_appears_in_the_deleted_array(): void
    {
        $owner = User::factory()->create(['is_owner' => true]);
        $group = Group::create(['name' => 'T', 'owner_id' => $owner->id]);
        [$user, $token] = $this->makeEntitledMember($group, $owner, 'a');

        RecallNote::create([
            'group_id' => $group->id, 'author_id' => $user->id, 'external_id' => 'alive.md',
            'title' => 'Alive', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);

        $response = $this->withToken($token)->getJson('/v1/recall/pull')->assertStatus(200)->json('deleted');
        $this->assertCount(0, $response);
    }

    public function test_a_user_never_sees_another_groups_tombstones(): void
    {
        $owner  = User::factory()->create(['is_owner' => true]);
        $groupA = Group::create(['name' => 'A', 'owner_id' => $owner->id]);
        $groupB = Group::create(['name' => 'B', 'owner_id' => User::factory()->create()->id]);

        [$userA, $tokenA] = $this->makeEntitledMember($groupA, $owner, 'a');
        [$userB, ] = $this->makeEntitledMember($groupB, $owner, 'b');

        $noteB = RecallNote::create([
            'group_id' => $groupB->id, 'author_id' => $userB->id, 'external_id' => 'b.md',
            'title' => 'B', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);
        $noteB->delete();

        $response = $this->withToken($tokenA)->getJson('/v1/recall/pull')->assertStatus(200)->json('deleted');
        $this->assertCount(0, $response);
    }
}
