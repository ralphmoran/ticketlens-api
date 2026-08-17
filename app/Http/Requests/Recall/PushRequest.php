<?php

namespace App\Http\Requests\Recall;

use App\Rules\ValidUtf8;
use Illuminate\Foundation\Http\FormRequest;

class PushRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Matches the CLI's own EXTERNAL_ID_PATTERN (recall-vault.mjs) exactly —
            // RecallSecretScanner exempts this field from its entropy heuristic on
            // the assumption it's always this system-generated shape, never
            // user-authored text. That assumption must be enforced here, not just
            // true by CLI convention.
            'external_id' => ['required', 'string', 'max:100', 'regex:/^\d+-[0-9a-f]{6}\.md$/'],
            // title/body/aliases/tags/sources are free text RecallSecretScanner
            // scans with /u (PCRE_UTF8) regexes — invalid UTF-8 would make those
            // silently return false instead of matching, so it's rejected here,
            // at the boundary, rather than left for the scanner to crash on.
            'title'       => ['required', 'string', 'max:200', new ValidUtf8()],
            'body'        => ['required', 'string', 'max:50000', new ValidUtf8()],
            'aliases'     => ['sometimes', 'array', 'max:10'],
            'aliases.*'   => ['string', 'max:200', new ValidUtf8()],
            'tickets'     => ['sometimes', 'array', 'max:20'],
            // Must match the CLI's TICKET_KEY_PATTERN (skills/jtb/scripts/lib/cli.mjs) —
            // a stricter letters-only prefix silently rejects real keys like CNV1-2.
            'tickets.*'   => ['string', 'regex:/^[A-Z][A-Z0-9]+-\d+$/', 'max:50'],
            'tags'        => ['sometimes', 'array', 'max:20'],
            'tags.*'      => ['string', 'max:100', new ValidUtf8()],
            'sources'     => ['sometimes', 'array', 'max:20'],
            'sources.*'   => ['string', 'max:2048', new ValidUtf8()],
            // Local-creation instant from the CLI vault's `created` frontmatter
            // (recall-vault.mjs) — optional because a CLI version predating this
            // field never sends it, and a bad client-supplied value can only ever
            // corrupt a display timestamp, never authorization or storage safety.
            'captured_at' => ['sometimes', 'nullable', 'date'],
            // Optional explicit team target — resolved via RecallTeamResolver,
            // always scoped to the authenticated user's own memberships, so a
            // client-supplied id can never select a team the user doesn't
            // belong to. nullable: ConvertEmptyStringsToNull turns a sent ""
            // into null before validation runs.
            'group_id'    => ['sometimes', 'nullable', 'integer'],
            // Shape-only here — exact byte-size/count caps (10MB/file, 50MB
            // total, 20 files) are enforced by RecallAttachmentStorage after
            // base64 decoding, since a validated string length doesn't map
            // 1:1 to decoded byte size. max:20 here still bounds the array
            // itself cheaply, before any decoding work happens.
            'attachments'            => ['sometimes', 'array', 'max:20'],
            'attachments.*.filename' => ['required_with:attachments', 'string', 'max:255'],
            'attachments.*.content'  => ['required_with:attachments', 'string'],
        ];
    }
}
