<?php

namespace App\Http\Controllers\Console\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recall\UpdateSettingsRequest;
use App\Models\Group;
use App\Models\RecallNote;
use App\Models\RecallNoteAttachment;
use App\Models\RecallSettings;
use App\Models\User;
use App\Services\ActiveGroupResolver;
use App\Services\AuditService;
use App\Services\RecallStorage;
use App\Services\SseEventService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RecallController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ActiveGroupResolver $groupResolver,
    ) {}

    public function index(Request $request): Response
    {
        $group = $this->groupResolver->forRequest($request);

        $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in(['verified', 'unverified'])],
        ]);

        $search   = $request->string('search')->trim()->value();
        $status   = $request->string('status')->trim()->value();
        $authorId = $request->integer('author_id') ?: null;
        $tag      = $request->string('tag')->trim()->value();
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
                ->when($status, fn ($query) => $query->where('status', $status))
                ->when($authorId, fn ($query) => $query->where('author_id', $authorId))
                // whereJsonContains, not the search filter's LIKE: an exact tag
                // match ("test" must not match a note tagged only "testing").
                ->when($tag, fn ($query) => $query->whereJsonContains('tags', $tag))
                ->with(['author:id,name,tier,avatar_path', 'attachments'])
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
                    // Prefer the client's local-capture instant (49g) over this
                    // row's own created_at, which only ever reflects when the
                    // server first received the push — falls back for notes
                    // pushed before this column existed (captured_at is null).
                    'created_at' => ($note->captured_at ?? $note->created_at)->toIso8601String(),
                    'attachments' => $note->attachments->map(fn ($attachment) => [
                        'id'         => $attachment->id,
                        'filename'   => $attachment->filename,
                        'size_bytes' => $attachment->size_bytes,
                        'mime_type'  => $attachment->mime_type,
                    ])->all(),
                ])
            : null;

        $user = $request->user();

        $override = $group ? RecallSettings::where('group_id', $group->id)->first() : null;

        return Inertia::render('Console/Admin/Recall', [
            'group'         => $group ? ['id' => $group->id, 'name' => $group->name] : null,
            'notes'         => $notes,
            'canManage'     => $user->is_owner || $user->ownedGroup?->id === $group?->id,
            'authorOptions' => $group ? $this->authorOptionsFor($group) : [],
            'tagOptions'    => $group ? $this->tagOptionsFor($group) : [],
            'filters'       => [
                'search'    => $search,
                'per_page'  => $perPage,
                'status'    => $status,
                'author_id' => $authorId,
                'tag'       => $tag,
            ],
            'settings'  => [
                'values'     => $override
                    ? $override->only(array_keys(RecallSettings::DEFAULTS))
                    : RecallSettings::DEFAULTS,
                'isOverride' => $override !== null,
                'bounds'     => RecallSettings::BOUNDS,
            ],
        ]);
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private function authorOptionsFor(Group $group): array
    {
        $authorIds = RecallNote::where('group_id', $group->id)
            ->whereNotNull('author_id')
            ->distinct()
            ->pluck('author_id');

        return User::whereIn('id', $authorIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $author) => ['id' => $author->id, 'name' => $author->name])
            ->all();
    }

    private function tagOptionsFor(Group $group): array
    {
        return RecallNote::where('group_id', $group->id)
            ->pluck('tags')
            ->flatten()
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
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

    // Only PDFs get an inline disposition (so the Console can preview them in
    // an <iframe> — Chrome's built-in PDF.js viewer sandboxes embedded PDF
    // JS the same way it does for any other origin's PDF, unlike HTML/SVG
    // served inline, which would execute directly in this origin's context).
    // The decision reads $attachment->mime_type, set server-side by finfo at
    // upload time (RecallAttachmentStorage::detectMime) — never a request
    // parameter — so a client can't ask for inline disposition on anything
    // else. Every other type keeps the forced-attachment download that
    // blocks the SVG/HTML script-injection vectors this was built to stop.
    private const INLINE_PREVIEW_MIME_TYPES = ['application/pdf'];

    public function downloadAttachment(Request $request, RecallNote $note, RecallNoteAttachment $attachment): StreamedResponse
    {
        // Same group-scoping as verify()/destroy(), plus an explicit check
        // that the attachment actually belongs to the note in the URL — two
        // separate route-model-bound ids that must agree, not just each be
        // independently valid, or one team's attachment id could be paired
        // with another team's note id to bypass the group check entirely.
        $group = $this->groupResolver->forRequest($request);
        abort_unless($group !== null && $note->group_id === $group->id && $attachment->recall_note_id === $note->id, 403);

        abort_unless(Storage::disk('local')->exists($attachment->disk_path), 404);

        if (in_array($attachment->mime_type, self::INLINE_PREVIEW_MIME_TYPES, true)) {
            // The global SecurityHeaders middleware defaults every response
            // to X-Frame-Options: DENY, which would otherwise block the
            // Console's own <iframe> preview just as effectively as it
            // blocks a third-party site framing this page. SAMEORIGIN opts
            // this one response into being embeddable, but only by pages on
            // this same origin — a third-party site still can't frame it.
            $response = Storage::disk('local')->response($attachment->disk_path, $attachment->filename);
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

            return $response;
        }

        return Storage::disk('local')->download($attachment->disk_path, $attachment->filename);
    }

    public function previewText(Request $request, RecallNote $note, RecallNoteAttachment $attachment): \Illuminate\Http\JsonResponse
    {
        $group = $this->groupResolver->forRequest($request);
        abort_unless($group !== null && $note->group_id === $group->id && $attachment->recall_note_id === $note->id, 403);

        // Only ever reads text/* — never used to preview a binary format,
        // same server-decided (not client-decided) gate as the inline PDF
        // disposition above.
        abort_unless(str_starts_with($attachment->mime_type, 'text/'), 422);
        abort_unless(Storage::disk('local')->exists($attachment->disk_path), 404);

        // Reads a bounded prefix rather than the whole file — a preview
        // snippet has no reason to ever buffer a full 10MB text attachment
        // into a JSON response.
        $maxBytes = 2000;
        $handle = Storage::disk('local')->readStream($attachment->disk_path);
        $chunk = fread($handle, $maxBytes + 1);
        fclose($handle);

        $truncated = strlen($chunk) > $maxBytes;
        // mb_strcut, not substr — a plain byte-offset cut risks splitting a
        // multi-byte UTF-8 character in half, which would either corrupt the
        // JSON response or make json_encode() fail outright on invalid UTF-8.
        $snippet = $truncated ? mb_strcut($chunk, 0, $maxBytes, 'UTF-8') : $chunk;

        return response()->json(['text' => $snippet, 'truncated' => $truncated]);
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
