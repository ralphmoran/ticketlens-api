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

    public function test_permissions_policy_header_present(): void
    {
        $response = $this->get('/console/login');

        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), usb=()');
    }

    public function test_x_frame_options_defaults_to_deny(): void
    {
        $response = $this->get('/console/login');

        $response->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_x_frame_options_set_by_a_controller_is_not_clobbered_back_to_deny(): void
    {
        // Regression lock for the Recall PDF-preview <iframe> fix: this
        // middleware used to unconditionally overwrite X-Frame-Options,
        // which silently defeated any controller's more specific choice.
        // SecurityHeaders is already global (bootstrap/app.php), so this
        // route needs no explicit middleware attachment.
        \Illuminate\Support\Facades\Route::get('/__test/sameorigin-probe', function () {
            return response('ok')->header('X-Frame-Options', 'SAMEORIGIN');
        });

        $response = $this->get('/__test/sameorigin-probe');

        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
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
