<?php

describe('SSE UI Reactions — wiring specification', function () {
    it('TlToastStack component exists', function () {
        expect(file_exists(
            dirname(__DIR__, 3) . '/resources/js/components/TlToastStack.vue',
        ))->toBeTrue();
    });

    it('TlRuleBanner component exists', function () {
        expect(file_exists(
            dirname(__DIR__, 3) . '/resources/js/components/TlRuleBanner.vue',
        ))->toBeTrue();
    });

    it('ConsoleLayout mounts TlToastStack', function () {
        $layout = file_get_contents(
            dirname(__DIR__, 3) . '/resources/js/Layouts/ConsoleLayout.vue',
        );
        expect($layout)->toContain('TlToastStack');
        expect($layout)->toContain('<TlToastStack');
    });

    it('ConsoleLayout mounts TlRuleBanner', function () {
        $layout = file_get_contents(
            dirname(__DIR__, 3) . '/resources/js/Layouts/ConsoleLayout.vue',
        );
        expect($layout)->toContain('TlRuleBanner');
        expect($layout)->toContain('<TlRuleBanner');
    });

    it('TlToastStack watches lastEvent and maps both event types to messages', function () {
        $component = file_get_contents(
            dirname(__DIR__, 3) . '/resources/js/components/TlToastStack.vue',
        );
        expect($component)->toContain('lastEvent');
        expect($component)->toContain('triage.pushed');
        expect($component)->toContain('rule.changed');
        expect($component)->toContain('tl-toast-stack');
        expect($component)->toContain('tl-toast-item');
    });

    it('TlRuleBanner shows on rule.changed and can be dismissed', function () {
        $component = file_get_contents(
            dirname(__DIR__, 3) . '/resources/js/components/TlRuleBanner.vue',
        );
        expect($component)->toContain('rule.changed');
        expect($component)->toContain('visible');
        expect($component)->toContain('tl-banner');
    });

    it('Rules page reloads on rule.changed SSE event', function () {
        $page = file_get_contents(
            dirname(__DIR__, 3) . '/resources/js/Pages/Console/Admin/Rules.vue',
        );
        expect($page)->toContain('useEventsStore');
        expect($page)->toContain('rule.changed');
        expect($page)->toContain('router.reload');
    });

    it('Queue page reloads on triage.pushed SSE event', function () {
        $page = file_get_contents(
            dirname(__DIR__, 3) . '/resources/js/Pages/Console/Queue.vue',
        );
        expect($page)->toContain('useEventsStore');
        expect($page)->toContain('triage.pushed');
        expect($page)->toContain('router.reload');
    });

    it('banners.css defines tl-toast-stack and tl-toast-item classes', function () {
        $css = file_get_contents(
            dirname(__DIR__, 3) . '/resources/css/components/banners.css',
        );
        expect($css)->toContain('.tl-toast-stack');
        expect($css)->toContain('.tl-toast-item');
        expect($css)->toContain('.tl-toast-item--info');
        expect($css)->toContain('.tl-toast-item--success');
    });
});
