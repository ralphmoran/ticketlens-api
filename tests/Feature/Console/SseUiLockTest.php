<?php

describe('SSE UI regressions — invariants that must not change', function () {
    it('tl-toast CSS class retains fixed top-20 right-4 positioning', function () {
        $css = file_get_contents(
            dirname(__DIR__, 3) . '/resources/css/components/banners.css',
        );

        expect($css)->toContain('.tl-toast {');
        expect($css)->toContain('@apply fixed');
        expect($css)->toContain('top-20');
        expect($css)->toContain('right-4');
    });

    it('tl-toast-stack CSS class retains fixed bottom-4 right-4 positioning', function () {
        $css = file_get_contents(
            dirname(__DIR__, 3) . '/resources/css/components/banners.css',
        );

        expect($css)->toContain('.tl-toast-stack {');
        expect($css)->toContain('bottom-4');
        expect($css)->toContain('right-4');
        expect($css)->toContain('flex-col');
    });

    it('tl-sse-banner CSS class retains mx-4 mt-4 mb-4 margins', function () {
        $css = file_get_contents(
            dirname(__DIR__, 3) . '/resources/css/components/banners.css',
        );

        expect($css)->toContain('.tl-sse-banner {');
        expect($css)->toContain('mx-4');
        expect($css)->toContain('mt-4');
        expect($css)->toContain('mb-4');
    });

    it('ConsoleLayout retains the impersonation banner markup', function () {
        $layout = file_get_contents(
            dirname(__DIR__, 3) . '/resources/js/Layouts/ConsoleLayout.vue',
        );

        expect($layout)->toContain('tl-imp-banner');
        expect($layout)->toContain('v-if="impersonating"');
    });

    it('SseEventService publish interface accepts groupId, type, and payload', function () {
        $service = file_get_contents(
            dirname(__DIR__, 3) . '/app/Services/SseEventService.php',
        );

        expect($service)->toContain('class SseEventService');
        expect($service)->toContain('public function publish(');
        expect($service)->toContain('int $groupId');
        expect($service)->toContain('string $type');
        expect($service)->toContain('array $payload');
    });

    it('events store exposes lastEvent ref and dispatch function', function () {
        $store = file_get_contents(
            dirname(__DIR__, 3) . '/resources/js/stores/events.js',
        );

        expect($store)->toContain('lastEvent');
        expect($store)->toContain('dispatch');
        expect($store)->toContain('useEventsStore');
    });
});
