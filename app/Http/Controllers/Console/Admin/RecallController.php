<?php

namespace App\Http\Controllers\Console\Admin;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\RecallNote;
use App\Services\RecallStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RecallController extends Controller
{
    public function index(Request $request): Response
    {
        $group = $this->resolveGroup($request);

        $notes = $group
            ? RecallNote::where('group_id', $group->id)
                ->with('author')
                ->orderByDesc('updated_at')
                ->paginate(25)
                ->through(fn (RecallNote $note) => [
                    'id'         => $note->id,
                    'title'      => $note->title,
                    'tickets'    => $note->tickets,
                    'tags'       => $note->tags,
                    'author'     => $note->author?->name,
                    'status'     => $note->status,
                    'created_at' => $note->created_at->toIso8601String(),
                ])
            : null;

        $user = $request->user();

        return Inertia::render('Console/Admin/Recall', [
            'group'     => $group ? ['id' => $group->id, 'name' => $group->name] : null,
            'notes'     => $notes,
            'canVerify' => $user->is_owner || $user->ownedGroup?->id === $group?->id,
        ]);
    }

    public function verify(Request $request, RecallNote $note): RedirectResponse
    {
        // Same resolution as index(): owner reads group_id (this route is also inside
        // team.manager, which already lets owners through), non-owner is confirmed a
        // manager by that same middleware, so ownedGroup is guaranteed non-null there.
        $group = $this->resolveGroup($request);
        abort_unless($group !== null && $note->group_id === $group->id, 403);

        app(RecallStorage::class)->verify($note, $request->user());

        return back()->with('success', 'Note verified.');
    }

    private function resolveGroup(Request $request): ?Group
    {
        $user = $request->user();

        if ($user->is_owner) {
            $groupId = $request->integer('group_id');
            return $groupId ? Group::find($groupId) : null;
        }

        return $user->ownedGroup ?? $user->groups()->first();
    }
}
