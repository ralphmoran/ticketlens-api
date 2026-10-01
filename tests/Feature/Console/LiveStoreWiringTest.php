<?php

function consoleSource(string $relative): string
{
    return file_get_contents(dirname(__DIR__, 3) . '/resources/js/' . $relative);
}

describe('Live store wiring: which page reloads on which event', function () {
    it('Dashboard reloads on triage.pushed and members.changed', function () {
        $page = consoleSource('Pages/Console/Dashboard.vue');

        expect($page)->toContain('useLiveReload');
        expect($page)->toContain('triage.pushed');
        expect($page)->toContain('members.changed');
    });

    it('Team reloads on triage.pushed and members.changed', function () {
        $page = consoleSource('Pages/Console/Team.vue');

        expect($page)->toContain('useLiveReload');
        expect($page)->toContain('triage.pushed');
        expect($page)->toContain('members.changed');
    });

    it('Analytics reloads on usage.recorded', function () {
        $page = consoleSource('Pages/Console/Analytics.vue');

        expect($page)->toContain('useLiveReload');
        expect($page)->toContain('usage.recorded');
    });

    it('Admin Members reloads on members.changed', function () {
        $page = consoleSource('Pages/Console/Admin/Members.vue');

        expect($page)->toContain('useLiveReload');
        expect($page)->toContain('members.changed');
    });

    it('Admin Digests reloads on digest.changed', function () {
        $page = consoleSource('Pages/Console/Admin/Digests.vue');

        expect($page)->toContain('useLiveReload');
        expect($page)->toContain('digest.changed');
    });
});

describe('Live store wiring: transport', function () {
    it('useServerEvents forwards every event type the backend can publish', function () {
        $composable = consoleSource('composables/useServerEvents.js');

        foreach (['rule.changed', 'triage.pushed', 'notification.updated', 'members.changed', 'digest.changed', 'usage.recorded'] as $type) {
            expect($composable)->toContain($type);
        }
    });

    it('useLiveReload unsubscribes and cancels on unmount', function () {
        $composable = consoleSource('composables/useLiveReload.js');

        expect($composable)->toContain('onUnmounted');
        expect($composable)->toContain('cancel');
        expect($composable)->toContain('subscribe');
    });

    it('every backend event type has a matching frontend forward', function () {
        $service  = file_get_contents(dirname(__DIR__, 3) . '/app/Services/SseEventService.php');
        $frontend = consoleSource('composables/useServerEvents.js');

        preg_match_all("/'([a-z]+\.[a-z]+)'\s*=>/", $service, $matches);

        expect($matches[1])->not->toBeEmpty();
        foreach ($matches[1] as $type) {
            expect($frontend)->toContain($type);
        }
    });
});
