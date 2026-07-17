<?php

namespace App\Http\Requests\Recall;

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
            'title'       => ['required', 'string', 'max:200'],
            'body'        => ['required', 'string', 'max:50000'],
            'aliases'     => ['sometimes', 'array', 'max:10'],
            'aliases.*'   => ['string', 'max:200'],
            'tickets'     => ['sometimes', 'array', 'max:20'],
            // Must match the CLI's TICKET_KEY_PATTERN (skills/jtb/scripts/lib/cli.mjs) —
            // a stricter letters-only prefix silently rejects real keys like CNV1-2.
            'tickets.*'   => ['string', 'regex:/^[A-Z][A-Z0-9]+-\d+$/', 'max:50'],
            'tags'        => ['sometimes', 'array', 'max:20'],
            'tags.*'      => ['string', 'max:100'],
            'sources'     => ['sometimes', 'array', 'max:20'],
            'sources.*'   => ['string', 'max:2048'],
        ];
    }
}
