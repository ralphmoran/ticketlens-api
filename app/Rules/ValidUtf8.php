<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * PushRequest's route accepts JSON or form-encoded bodies alike, and
 * Laravel's 'string' rule only checks is_string() — neither path guarantees
 * valid UTF-8. RecallSecretScanner's whitespace regexes run in /u (PCRE_UTF8)
 * mode and return false on invalid UTF-8 input, which would otherwise reach
 * array_filter() as a TypeError (500) instead of a clean validation error.
 * Rejecting malformed encoding here, at the boundary, keeps the scanner free
 * to assume well-formed text.
 */
class ValidUtf8 implements ValidationRule
{
    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
            $fail('The :attribute field must be valid UTF-8 text.');
        }
    }
}
