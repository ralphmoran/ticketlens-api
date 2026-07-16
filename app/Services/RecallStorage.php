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
     * Upserts one note by (group_id, external_id) — a repeat push of the
     * same locally-authored note (same externalId) updates the existing row
     * instead of creating a duplicate.
     */
    public function push(Group $group, User $author, array $data): RecallNote
    {
        // firstOrNew, not updateOrCreate: status must be set to 'unverified' on
        // create but left untouched on update (a re-push must never reset an
        // already-verified note back to unverified) — two different write
        // paths that a single values array can't express.
        $note   = RecallNote::firstOrNew(['group_id' => $group->id, 'external_id' => $data['external_id']]);
        $isNew  = ! $note->exists;

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

        if ($isNew) {
            $note->status = 'unverified';
        }

        $note->save();

        return $note;
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
