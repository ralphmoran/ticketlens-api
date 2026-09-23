<?php

namespace App\Http\Requests;

use App\Rules\ValidUtf8;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreErrorReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cli_version'   => ['required', 'string', 'max:32'],
            'os'            => ['sometimes', 'nullable', 'string', 'max:64'],
            'command'       => ['sometimes', 'nullable', 'string', 'max:255', new ValidUtf8()],
            // Bounded generously but not unbounded — this is a diagnostic
            // message/trace, not a file upload; RecallSecretScanner's regexes
            // also run against these fields, so invalid UTF-8 is rejected
            // here rather than left for the scanner to silently no-match on.
            'message'       => ['required', 'string', 'max:10000', new ValidUtf8()],
            'stack_trace'   => ['sometimes', 'nullable', 'string', 'max:20000', new ValidUtf8()],
            'profile_tier'  => ['sometimes', 'nullable', Rule::in(['free', 'pro', 'team', 'enterprise'])],
            // Bounded like every other field (unlike message/stack_trace,
            // there's no auth on this endpoint to rate-limit an attacker
            // stuffing an unbounded payload in here) and restricted to
            // scalar values — diagnostic metadata is node version/platform
            // arch, not a place for nested free-form data from an
            // unauthenticated caller.
            'metadata'      => ['sometimes', 'nullable', 'array', 'max:20'],
            'metadata.*'    => ['nullable', function ($attribute, $value, $fail) {
                if (!is_scalar($value)) {
                    $fail('Report metadata values must be simple strings, numbers, or booleans.');
                } elseif (is_string($value) && mb_strlen($value) > 500) {
                    $fail('Report metadata values must be 500 characters or fewer.');
                }
            }],
        ];
    }
}
