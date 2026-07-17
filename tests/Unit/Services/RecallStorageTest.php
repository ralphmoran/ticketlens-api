<?php

namespace Tests\Unit\Services;

use App\Models\Group;
use App\Models\RecallNote;
use App\Models\User;
use App\Services\RecallStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecallStorageTest extends TestCase
{
    use RefreshDatabase;

    private RecallStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new RecallStorage();
    }

    private function makeGroupWithOwner(): Group
    {
        $owner = User::factory()->create();
        return Group::create(['name' => 'T', 'owner_id' => $owner->id]);
    }

    // ---- push ----

    public function test_push_creates_a_new_note(): void
    {
        $group  = $this->makeGroupWithOwner();
        $author = User::factory()->create();

        $note = $this->storage->push($group, $author, [
            'external_id' => '1700000000000-abcdef.md',
            'title'       => 'Retry gotcha',
            'tickets'     => ['PROD-1'],
            'tags'        => ['bug'],
            'sources'     => [],
            'body'        => 'Needs exponential backoff.',
        ]);

        $this->assertSame('Retry gotcha', $note->title);
        $this->assertSame($group->id, $note->group_id);
        $this->assertSame($author->id, $note->author_id);
        $this->assertSame('unverified', $note->status);
        $this->assertDatabaseCount('recall_notes', 1);
    }

    public function test_push_defaults_aliases_to_the_title_when_not_given(): void
    {
        $group  = $this->makeGroupWithOwner();
        $author = User::factory()->create();

        $note = $this->storage->push($group, $author, [
            'external_id' => '1700000000000-abcdef.md',
            'title'       => 'Retry gotcha',
            'body'        => 'x',
        ]);

        $this->assertSame(['Retry gotcha'], $note->aliases);
    }

    public function test_pushing_the_same_external_id_again_updates_the_same_row_instead_of_creating_a_duplicate(): void
    {
        $group  = $this->makeGroupWithOwner();
        $author = User::factory()->create();

        $this->storage->push($group, $author, ['external_id' => 'x.md', 'title' => 'First version', 'body' => 'a']);
        $this->storage->push($group, $author, ['external_id' => 'x.md', 'title' => 'Updated version', 'body' => 'b']);

        $this->assertDatabaseCount('recall_notes', 1);
        $this->assertSame('Updated version', RecallNote::first()->title);
    }

    public function test_push_never_changes_an_existing_notes_verified_status(): void
    {
        $group  = $this->makeGroupWithOwner();
        $author = User::factory()->create();
        $verifier = User::factory()->create();

        $note = $this->storage->push($group, $author, ['external_id' => 'x.md', 'title' => 'v1', 'body' => 'a']);
        $this->storage->verify($note, $verifier);

        $this->storage->push($group, $author, ['external_id' => 'x.md', 'title' => 'v2', 'body' => 'b']);

        $this->assertSame('verified', RecallNote::first()->status);
    }

    public function test_the_same_external_id_in_two_different_groups_creates_two_separate_rows(): void
    {
        $groupA = $this->makeGroupWithOwner();
        $groupB = $this->makeGroupWithOwner();
        $author = User::factory()->create();

        $this->storage->push($groupA, $author, ['external_id' => 'x.md', 'title' => 'A', 'body' => 'a']);
        $this->storage->push($groupB, $author, ['external_id' => 'x.md', 'title' => 'B', 'body' => 'b']);

        $this->assertDatabaseCount('recall_notes', 2);
    }

    // ---- pull ----

    public function test_pull_returns_only_notes_belonging_to_the_given_group(): void
    {
        $groupA = $this->makeGroupWithOwner();
        $groupB = $this->makeGroupWithOwner();
        $author = User::factory()->create();

        $this->storage->push($groupA, $author, ['external_id' => 'a.md', 'title' => 'A', 'body' => 'x']);
        $this->storage->push($groupB, $author, ['external_id' => 'b.md', 'title' => 'B', 'body' => 'x']);

        $result = $this->storage->pull($groupA);

        $this->assertCount(1, $result);
        $this->assertSame('A', $result->first()->title);
    }

    public function test_pull_with_since_only_returns_notes_updated_after_that_timestamp(): void
    {
        $group  = $this->makeGroupWithOwner();
        $author = User::factory()->create();

        $old = $this->storage->push($group, $author, ['external_id' => 'old.md', 'title' => 'Old', 'body' => 'x']);
        RecallNote::where('id', $old->id)->update(['updated_at' => now()->subDays(2)]);

        $this->storage->push($group, $author, ['external_id' => 'new.md', 'title' => 'New', 'body' => 'x']);

        $result = $this->storage->pull($group, now()->subDay());

        $this->assertCount(1, $result);
        $this->assertSame('New', $result->first()->title);
    }

    public function test_pull_without_since_returns_everything_up_to_the_limit(): void
    {
        $group  = $this->makeGroupWithOwner();
        $author = User::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->storage->push($group, $author, ['external_id' => "n{$i}.md", 'title' => "N{$i}", 'body' => 'x']);
        }

        $this->assertCount(3, $this->storage->pull($group));
    }

    public function test_pull_never_returns_more_than_the_configured_cap_even_when_since_is_null(): void
    {
        $group  = $this->makeGroupWithOwner();
        $author = User::factory()->create();

        for ($i = 0; $i < 205; $i++) {
            RecallNote::create([
                'group_id' => $group->id, 'author_id' => $author->id, 'external_id' => "n{$i}.md",
                'title' => "N{$i}", 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
            ]);
        }

        $this->assertLessThanOrEqual(200, $this->storage->pull($group)->count());
    }

    // ---- verify ----

    public function test_verify_sets_status_verified_at_and_verified_by(): void
    {
        $group    = $this->makeGroupWithOwner();
        $author   = User::factory()->create();
        $verifier = User::factory()->create();
        $note = $this->storage->push($group, $author, ['external_id' => 'x.md', 'title' => 't', 'body' => 'x']);

        $result = $this->storage->verify($note, $verifier);

        $this->assertSame('verified', $result->status);
        $this->assertNotNull($result->verified_at);
        $this->assertSame($verifier->id, $result->verified_by);
    }

    public function test_verifying_an_already_verified_note_is_a_no_op_immutable_provenance(): void
    {
        $group     = $this->makeGroupWithOwner();
        $author    = User::factory()->create();
        $firstVerifier  = User::factory()->create();
        $secondVerifier = User::factory()->create();
        $note = $this->storage->push($group, $author, ['external_id' => 'x.md', 'title' => 't', 'body' => 'x']);

        $this->storage->verify($note, $firstVerifier);
        $firstVerifiedAt = $note->fresh()->verified_at;

        $this->storage->verify($note->fresh(), $secondVerifier);

        $final = RecallNote::first();
        $this->assertSame($firstVerifier->id, $final->verified_by, 'the first verifier is the permanent record');
        $this->assertEquals($firstVerifiedAt, $final->verified_at);
    }

    // ---- soft delete ----

    public function test_deleting_a_note_soft_deletes_it_and_excludes_it_from_default_queries(): void
    {
        $group  = $this->makeGroupWithOwner();
        $author = User::factory()->create();
        $note   = $this->storage->push($group, $author, ['external_id' => 'x.md', 'title' => 't', 'body' => 'x']);

        $note->delete();

        $this->assertNull(RecallNote::find($note->id));
        $this->assertNotNull(RecallNote::withTrashed()->find($note->id)->deleted_at);
    }

    public function test_storage_delete_soft_deletes_the_note(): void
    {
        $group  = $this->makeGroupWithOwner();
        $author = User::factory()->create();
        $note   = $this->storage->push($group, $author, ['external_id' => 'x.md', 'title' => 't', 'body' => 'x']);

        $this->storage->delete($note);

        $this->assertNull(RecallNote::find($note->id));
        $this->assertNotNull(RecallNote::withTrashed()->find($note->id)->deleted_at);
    }

    public function test_pushing_the_same_external_id_after_deletion_restores_the_note_instead_of_violating_the_unique_constraint(): void
    {
        $group  = $this->makeGroupWithOwner();
        $author = User::factory()->create();
        $note   = $this->storage->push($group, $author, ['external_id' => 'x.md', 'title' => 'v1', 'body' => 'a']);
        $this->storage->delete($note);

        $restored = $this->storage->push($group, $author, ['external_id' => 'x.md', 'title' => 'v2', 'body' => 'b']);

        $this->assertDatabaseCount('recall_notes', 1);
        $this->assertNull($restored->deleted_at);
        $this->assertSame('v2', $restored->title);
    }

    public function test_a_repush_after_deletion_resets_status_to_unverified_even_if_it_was_verified_before_deletion(): void
    {
        $group    = $this->makeGroupWithOwner();
        $author   = User::factory()->create();
        $verifier = User::factory()->create();
        $note     = $this->storage->push($group, $author, ['external_id' => 'x.md', 'title' => 'v1', 'body' => 'a']);
        $this->storage->verify($note, $verifier);
        $this->storage->delete($note->fresh());

        $restored = $this->storage->push($group, $author, ['external_id' => 'x.md', 'title' => 'v2', 'body' => 'b']);

        $this->assertSame('unverified', $restored->status, 'a delete must not let a stale verification carry over silently on repush');
    }

    // ---- pull tombstones ----

    public function test_pull_tombstones_only_includes_notes_deleted_after_since(): void
    {
        $group  = $this->makeGroupWithOwner();
        $author = User::factory()->create();

        $old = $this->storage->push($group, $author, ['external_id' => 'old.md', 'title' => 'Old', 'body' => 'x']);
        $this->storage->delete($old);
        RecallNote::withTrashed()->where('id', $old->id)->update(['deleted_at' => now()->subDays(2)]);

        $recent = $this->storage->push($group, $author, ['external_id' => 'recent.md', 'title' => 'Recent', 'body' => 'x']);
        $this->storage->delete($recent);

        $tombstones = $this->storage->pullTombstones($group, now()->subDay());

        $this->assertCount(1, $tombstones);
        $this->assertSame('recent.md', $tombstones->first()->external_id);
    }

    public function test_pull_tombstones_are_scoped_to_the_given_group(): void
    {
        $groupA = $this->makeGroupWithOwner();
        $groupB = $this->makeGroupWithOwner();
        $author = User::factory()->create();

        $noteA = $this->storage->push($groupA, $author, ['external_id' => 'a.md', 'title' => 'A', 'body' => 'x']);
        $this->storage->delete($noteA);
        $noteB = $this->storage->push($groupB, $author, ['external_id' => 'b.md', 'title' => 'B', 'body' => 'x']);
        $this->storage->delete($noteB);

        $tombstones = $this->storage->pullTombstones($groupA);

        $this->assertCount(1, $tombstones);
        $this->assertSame('a.md', $tombstones->first()->external_id);
    }

    public function test_pull_tombstones_never_returns_more_than_the_configured_cap(): void
    {
        // Tombstone rows carry no body — a much higher cap than pull()'s
        // PULL_LIMIT is safe (tiny rows) and meaningfully reduces the chance
        // of a heavy-deletion group ever orphaning a local file (the oldest
        // excess tombstones would otherwise fall out of the window and never
        // be returned again once the client's cursor moves past them).
        $group  = $this->makeGroupWithOwner();
        $author = User::factory()->create();

        for ($i = 0; $i < 1005; $i++) {
            $note = RecallNote::create([
                'group_id' => $group->id, 'author_id' => $author->id, 'external_id' => "n{$i}.md",
                'title' => "N{$i}", 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
            ]);
            $note->delete();
        }

        $this->assertSame(1000, $this->storage->pullTombstones($group)->count());
    }
}
