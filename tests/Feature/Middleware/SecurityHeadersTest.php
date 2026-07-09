<?php

namespace Tests\Feature\Middleware;

use Tests\TestCase;

/**
 * Locks SecurityHeaders' CSP directive: script-src stays 'self' only —
 * no unsafe-inline, no nonce — the control the app.blade.php inline-script
 * fix (audit §3.9) relies on staying intact.
 */
class SecurityHeadersTest extends TestCase
{
    public function test_csp_header_present_and_script_src_is_self_only(): void
    {
        $response = $this->get('/console/login');

        $response->assertHeader('Content-Security-Policy');

        $scriptSrc = $this->scriptSrcDirective($response->headers->get('Content-Security-Policy'));

        $this->assertSame("'self'", $scriptSrc);
    }

    public function test_no_csp_header_when_local(): void
    {
        $original = app()->environment();

        try {
            app()->detectEnvironment(fn () => 'local');
            config(['app.env' => 'local']);

            $response = $this->get('/console/login');
            $response->assertHeaderMissing('Content-Security-Policy');
        } finally {
            app()->detectEnvironment(fn () => $original);
            config(['app.env' => $original]);
        }
    }

    private function scriptSrcDirective(string $csp): string
    {
        foreach (explode(';', $csp) as $directive) {
            $directive = trim($directive);
            if (str_starts_with($directive, 'script-src ')) {
                return trim(substr($directive, strlen('script-src ')));
            }
        }

        $this->fail("script-src directive not found in CSP header: {$csp}");
    }
}
