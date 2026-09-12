<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Locks backlog #28 fix (b): a raw <a href="/console/..."> triggers a full
 * browser reload instead of an Inertia SPA visit, reproducing the body's
 * dark-background flash ce4c28d already fixed for the persistent nav chrome.
 * External/mailto/target=_blank anchors, and file-download/attachment links
 * (which must NOT be Inertia-routed), are untouched by this scan.
 *
 * Three detection rules (a static "/console" href only tells part of it —
 * TlSettingsTabs.vue's `:href="tab.href"` and Alerts.vue's
 * `:href="alertUrl('/digests')"` both hid a real internal link this way):
 *  1. static href="/console..." — literal internal route.
 *  2. dynamic :href containing a quoted path literal (e.g. a helper call
 *     like `alertUrl('/digests')`) — the function builds an internal route.
 *  3. any dynamic :href inside components/ — that tree is nav-only, no
 *     legitimate external/attachment links live there.
 */
class ConsoleInternalLinksUseInertiaTest extends TestCase
{
    public function test_no_raw_anchor_tags_link_to_internal_console_routes(): void
    {
        $jsDir = dirname(__DIR__, 2) . '/resources/js';
        $componentsDir = $jsDir . '/components/';
        $offenders = [];

        foreach ($this->vueFiles($jsDir) as $file) {
            $content = file_get_contents($file);
            $inComponents = str_starts_with($file, $componentsDir);

            preg_match_all('/<a\s[^>]*>/', $content, $tags);

            foreach ($tags[0] as $tag) {
                if (!preg_match('/\s(?:href|:href)=(["\'])((?:(?!\1).)*)\1/', $tag, $attr)) {
                    continue;
                }

                $isDynamic = (bool) preg_match('/\s:href=/', $tag);
                $value = $attr[2];

                $flag = $isDynamic
                    ? ($inComponents || str_contains($value, '/console') || preg_match('/[\'"`]\/[a-zA-Z][^\'"`]*[\'"`]/', $value))
                    : str_starts_with($value, '/console');

                if ($flag) {
                    $offenders[] = str_replace($jsDir . '/', '', $file) . ': ' . trim($tag);
                }
            }
        }

        $this->assertSame([], $offenders, "Raw <a> tags linking to internal /console routes found:\n" . implode("\n", $offenders));
    }

    /**
     * @return list<string>
     */
    private function vueFiles(string $dir): array
    {
        $files = [];

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (is_dir($path)) {
                $files = array_merge($files, $this->vueFiles($path));
            } elseif (str_ends_with($entry, '.vue')) {
                $files[] = $path;
            }
        }

        return $files;
    }
}
