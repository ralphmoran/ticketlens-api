<?php

namespace Tests\Unit\Rules;

use App\Rules\ValidUtf8;
use Tests\TestCase;

class ValidUtf8Test extends TestCase
{
    private function fails(string $value): bool
    {
        $failed = false;
        (new ValidUtf8())->validate('field', $value, function () use (&$failed) {
            $failed = true;
        });
        return $failed;
    }

    public function test_ordinary_ascii_text_passes(): void
    {
        $this->assertFalse($this->fails('Needs exponential backoff, not a fixed delay.'));
    }

    public function test_valid_multibyte_utf8_passes(): void
    {
        $this->assertFalse($this->fails("caf\u{00e9} \u{4e2d}\u{6587} \u{1f600}"));
    }

    public function test_invalid_utf8_byte_sequence_fails(): void
    {
        // 0xFF/0xFE are never valid UTF-8 lead bytes — this is exactly the
        // shape that made RecallSecretScanner's /u regexes return false
        // instead of matching, crashing array_filter() downstream (backlog 1c
        // hardening). Rejecting it here keeps that invariant true for every
        // caller, not just PushRequest.
        $this->assertTrue($this->fails("AKIA\xFF\xFEIOSFODNN7EXAMPLE"));
    }
}
