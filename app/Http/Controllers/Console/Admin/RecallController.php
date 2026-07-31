<?php

namespace App\Http\Controllers\Console\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recall\UpdateSettingsRequest;
use App\Models\RecallNote;
use App\Models\RecallSettings;
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
                    // tags is a JSON array column; its text representation still
                    // contains each tag as a plain substring, so LIKE matches it
                    // the same way on both MySQL and SQLite without a migration.
                    $query->where('title', 'like', "%{$search}%")
                          ->orWhere('body', 'like', "%{$search}%")
                          ->orWhereRaw('tags LIKE ?', ["%{$search}%"]);
                }))
                ->with('author:id,name,tier,avatar_path')
                ->orderByDesc('updated_at')
                ->paginate($perPage)
                ->withQueryString()
                ->through(fn (RecallNote $note) => [
                    'id'         => $note->id,
                    'title'      => $note->title,
                    'body'       => $note->body,
                    'tickets'    => $note->tickets,
                    'tags'       => $note->tags,
                    'author'     => $note->author ? [
                        'name'       => $note->author->name,
                        'tier'       => $note->author->tier,
                        'avatar_url' => $note->author->avatarUrl(),
                    ] : null,
                    'status'     => $note->status,
                    'created_at' => $note->created_at->toIso8601String(),
                ])
            : null;

        $user = $request->user();

        $override = $group ? RecallSettings::where('group_id', $group->id)->first() : null;

        return Inertia::render('Console/Admin/Recall', [
            'group'     => $group ? ['id' => $group->id, 'name' => $group->name] : null,
            'notes'     => $notes,
            'canManage' => $user->is_owner || $user->ownedGroup?->id === $group?->id,
            'filters'   => ['search' => $search, 'per_page' => $perPage],
            'settings'  => [
                'values'     => $override
                    ? $override->only(array_keys(RecallSettings::DEFAULTS))
                    : RecallSettings::DEFAULTS,
                'isOverride' => $override !== null,
                'bounds'     => RecallSettings::BOUNDS,
            ],
        ]);
    }

    public function bulkVerify(Request $request): RedirectResponse
    {
        // Same resolution as verify(): owner reads group_id, non-owner is a
        // confirmed manager via team.manager middleware.
        $group = $this->groupResolver->forRequest($request);
        abort_unless($group !== null, 403);

        // max:100 mirrors index()'s per-page ceiling — the largest a manager's
        // page-scoped selection can ever legitimately be.
        $validated = $request->validate([
            'ids'   => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
        ]);

        // whereIn+where, not abort_unless per id: a stale selection (e.g. a note
        // deleted by a teammate between page load and submit) should still
        // verify the rest of the batch rather than failing the whole request.
        // Notes outside this group are silently excluded — same authorization
        // posture as the single-note IDOR guard, applied per-row instead of
        // per-request.
        $notes = RecallNote::whereIn('id', $validated['ids'])->where('group_id', $group->id)->get();

        $storage = app(RecallStorage::class);
        foreach ($notes as $note) {
            $storage->verify($note, $request->user());
        }

        $count = $notes->count();

        if ($count > 0) {
            app(SseEventService::class)->publish($group->id, 'notification.updated', []);
        }

        return back()->with('success', $count === 1 ? '1 note verified.' : "{$count} notes verified.");
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        // Same resolution + authorization shape as bulkVerify() — see its comment.
        $group = $this->groupResolver->forRequest($request);
        abort_unless($group !== null, 403);

        $validated = $request->validate([
            'ids'   => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
        ]);

        $notes = RecallNote::whereIn('id', $validated['ids'])->where('group_id', $group->id)->get();

        $storage = app(RecallStorage::class);
        foreach ($notes as $note) {
            // Captured per-note before delete(), same reasoning as destroy():
            // target is null (a RecallNote isn't a User), logged after delete()
            // succeeds so a failed delete never leaves a misleading trail entry.
            $oldValue = ['title' => $note->title, 'external_id' => $note->external_id, 'group_id' => $note->group_id];
            $storage->delete($note);
            $this->audit->logFromRequest(
                request: $request,
                action: 'recall.deleted',
                oldValue: $oldValue,
                metadata: ['note_id' => $note->id],
            );
        }

        $count = $notes->count();

        if ($count > 0) {
            app(SseEventService::class)->publish($group->id, 'notification.updated', []);
        }

        return back()->with('success', $count === 1 ? '1 note deleted.' : "{$count} notes deleted.");
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

    public function updateSettings(UpdateSettingsRequest $request): RedirectResponse
    {
        // Same resolution + authorization shape as verify()/destroy() — this route
        // is also inside team.manager, so a non-owner here is already a confirmed
        // manager of *some* group; re-resolving here still confirms it's *this* one.
        $group = $this->groupResolver->forRequest($request);
        abort_unless($group !== null, 403);

        $oldValue = RecallSettings::where('group_id', $group->id)->first()
            ?->only(array_keys(RecallSettings::DEFAULTS)) ?? RecallSettings::DEFAULTS;

        $settings = RecallSettings::updateOrCreate(
            ['group_id' => $group->id],
            $request->validated(),
        );

        $this->audit->logFromRequest(
            request: $request,
            action: 'recall.settings_updated',
            oldValue: $oldValue,
            newValue: $settings->only(array_keys(RecallSettings::DEFAULTS)),
        );

        return back()->with('success', 'Recall settings updated.');
    }
}
