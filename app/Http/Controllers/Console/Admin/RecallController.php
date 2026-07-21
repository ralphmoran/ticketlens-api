<?php

namespace App\Http\Controllers\Console\Admin;

use App\Http\Controllers\Controller;
use App\Models\RecallNote;
use App\Services\ActiveGroupResolver;
use App\Services\AuditService;
use App\Services\RecallStorage;
use App\Services\SseEventService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RecallController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ActiveGroupResolver $groupResolver,
    ) {}

    public function index(Request $request): Response
    {
        $group  = $this->groupResolver->forRequest($request);
        $search = $request->string('search')->trim()->value();
        // Same clamp as AuditController::index() — bounds page size the same
        // way across every admin list page in this codebase.
        $perPage = min(max(1, (int) $request->input('per_page', 10)), 100);

        $notes = $group
            ? RecallNote::where('group_id', $group->id)
                ->when($search, fn ($query) => $query->where(function ($query) use ($search) {
                    $query->where('title', 'like', "%{$search}%")
                          ->orWhere('body', 'like', "%{$search}%");
                }))
                ->with('author')
                ->orderByDesc('updated_at')
                ->paginate($perPage)
                ->withQueryString()
                ->through(fn (RecallNote $note) => [
                    'id'         => $note->id,
                    'title'      => $note->title,
                    'body'       => $note->body,
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
            'canManage' => $user->is_owner || $user->ownedGroup?->id === $group?->id,
            'filters'   => ['search' => $search, 'per_page' => $perPage],
        ]);
    }

    public function verify(Request $request, RecallNote $note): RedirectResponse
    {
        // Same resolution as index(): owner reads group_id (this route is also inside
        // team.manager, which already lets owners through), non-owner is confirmed a
        // manager by that same middleware, so ownedGroup is guaranteed non-null there.
        $group = $this->groupResolver->forRequest($request);
        abort_unless($group !== null && $note->group_id === $group->id, 403);

        app(RecallStorage::class)->verify($note, $request->user());
        app(SseEventService::class)->publish($group->id, 'notification.updated', []);

        return back()->with('success', 'Note verified.');
    }

    public function destroy(Request $request, RecallNote $note): RedirectResponse
    {
        // Same resolution + authorization shape as verify() — see its comment.
        $group = $this->groupResolver->forRequest($request);
        abort_unless($group !== null && $note->group_id === $group->id, 403);

        // title/external_id/group_id captured before deletion so the log still
        // identifies what was removed. target is null, not the actor — a
        // RecallNote isn't a User, and AuditService::log()'s target column only
        // ever points at one. Logged after delete() succeeds, matching every
        // other destructive action in this codebase — a failed delete must
        // never leave a misleading "deleted" entry in the trail.
        $oldValue = ['title' => $note->title, 'external_id' => $note->external_id, 'group_id' => $note->group_id];

        app(RecallStorage::class)->delete($note);
        app(SseEventService::class)->publish($group->id, 'notification.updated', []);

        $this->audit->logFromRequest(
            request: $request,
            action: 'recall.deleted',
            oldValue: $oldValue,
            metadata: ['note_id' => $note->id],
        );

        return back()->with('success', 'Note deleted.');
    }
}
