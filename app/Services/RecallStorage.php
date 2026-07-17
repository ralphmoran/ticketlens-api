<?php

namespace App\Services;

use App\Models\Group;
use App\Models\RecallNote;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class RecallStorage
{
    /**
     * Pull is capped per request — an unbounded first pull would mean every
     * note's full body (up to 50k chars each) in memory, over the wire, and
     * written to disk at once. Repeat pulls page forward via $since.
     */
    private const PULL_LIMIT = 200;

    /**
     * A tombstone row carries no body (just external_id, tickets, deleted_at)
     * — a much higher cap than PULL_LIMIT is cheap and meaningfully reduces
     * the chance a heavy-deletion group ever orphans a local file: the oldest
     * excess tombstones fall out of the returned window once the client's
     * `since` cursor moves past them, and are never returned again.
     */
    private const TOMBSTONE_LIMIT = 1000;

    /**
     * Upserts one note by (group_id, external_id) — a repeat push of the
     * same locally-authored note (same externalId) updates the existing row
     * instead of creating a duplicate.
     */
    public function push(Group $group, User $author, array $data): RecallNote
    {
        // withTrashed(), not the default scope: a re-push of a previously
        // deleted note's external_id must restore it, not collide with the
        // (group_id, external_id) unique constraint by trying to insert a
        // second row.
        //
        // firstOrNew, not updateOrCreate: status must be set to 'unverified' on
        // create but left untouched on a normal update (a re-push must never
        // reset an already-verified note back to unverified) — two different
        // write paths that a single values array can't express.
        $note      = RecallNote::withTrashed()->firstOrNew(['group_id' => $group->id, 'external_id' => $data['external_id']]);
        $isNew     = ! $note->exists;
        $wasTrashed = $note->trashed();

        if ($wasTrashed) {
            $note->restore();
        }

        $note->fill([
            'author_id'          => $author->id,
            'tracker_profile_id' => $data['tracker_profile_id'] ?? null,
            'title'              => $data['title'],
            'aliases'            => $data['aliases'] ?? [$data['title']],
            'tickets'            => $data['tickets'] ?? [],
            'tags'               => $data['tags'] ?? [],
            'sources'            => $data['sources'] ?? [],
            'body'               => $data['body'],
            'published_at'       => now(),
        ]);

        if ($isNew || $wasTrashed) {
            // A deleted note's prior verification must never carry over
            // silently on repush — the delete may have been exactly because
            // the content was wrong.
            $note->status = 'unverified';
        }

        $note->save();

        return $note;
    }

    /**
     * Soft-deletes a note. Never a hard delete: a deleted note's row must
     * still exist as a tombstone so pullTombstones() can tell already-synced
     * clients to remove their local copy.
     */
    public function delete(RecallNote $note): void
    {
        $note->delete();
    }

    /**
     * Returns a group's notes, optionally only those updated since a given
     * timestamp, most-recently-updated first, capped at PULL_LIMIT.
     */
    public function pull(Group $group, ?Carbon $since = null): Collection
    {
        return RecallNote::where('group_id', $group->id)
            ->when($since, fn ($query) => $query->where('updated_at', '>', $since))
            ->with('author')
            ->orderByDesc('updated_at')
            ->limit(self::PULL_LIMIT)
            ->get();
    }

    /**
     * Returns notes deleted since a given timestamp — tombstones a client
     * needs so it can remove its own already-pulled local copy. Kept
     * separate from pull() rather than folded into its return shape: pull()'s
     * existing Collection-of-live-notes contract has its own callers/tests
     * and must not change. Only external_id + tickets (needed for the CLI's
     * O(1) local file path resolution) + deleted_at are selected — a
     * tombstone has no reason to carry body/title/etc.
     */
    public function pullTombstones(Group $group, ?Carbon $since = null): Collection
    {
        return RecallNote::withTrashed()
            ->where('group_id', $group->id)
            ->whereNotNull('deleted_at')
            ->when($since, fn ($query) => $query->where('deleted_at', '>', $since))
            ->orderByDesc('deleted_at')
            ->limit(self::TOMBSTONE_LIMIT)
            ->get(['external_id', 'tickets', 'deleted_at']);
    }

    /**
     * Promotes a note to verified. Idempotent by design — immutable
     * provenance means the first verification is the permanent record; a
     * second verify call on an already-verified note is a no-op, not a
     * timestamp/verifier overwrite.
     */
    public function verify(RecallNote $note, User $verifier): RecallNote
    {
        if ($note->status === 'verified') {
            return $note;
        }

        $note->update([
            'status'      => 'verified',
            'verified_at' => now(),
            'verified_by' => $verifier->id,
        ]);

        return $note;
    }
}
