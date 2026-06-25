<?php

use function Pest\Laravel\{artisan};

describe('SSE UI regressions — invariants that must not change', function () {
    it('tl-toast CSS class retains fixed positioning', function () {
        $css = file_get_contents(
            dirname(__DIR__, 3) . '/resources/css/components/banners.css',
        );

        expect($css)->toContain('.tl-toast {');
        expect($css)->toContain('@apply fixed');
    });

    it('ConsoleLayout retains the impersonation banner markup', function () {
        $layout = file_get_contents(
            dirname(__DIR__, 3) . '/resources/js/Layouts/ConsoleLayout.vue',
        );

        expect($layout)->toContain('tl-imp-banner');
        expect($layout)->toContain('v-if="impersonating"');
    });
});
