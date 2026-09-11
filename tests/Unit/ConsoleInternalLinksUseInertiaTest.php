<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Locks backlog #28 fix (b): a raw <a href="/console/..."> triggers a full
 * browser reload instead of an Inertia SPA visit, reproducing the body's
 * dark-background flash ce4c28d already fixed for the persistent nav chrome.
 * External/mailto/target=_blank anchors are untouched by this scan.
 */
class ConsoleInternalLinksUseInertiaTest extends TestCase
{
    public function test_no_raw_anchor_tags_link_to_internal_console_routes(): void
    {
        $jsDir = dirname(__DIR__, 2) . '/resources/js';
        $offenders = [];

        foreach ($this->vueFiles($jsDir) as $file) {
            $content = file_get_contents($file);

            preg_match_all('/<a\s[^>]*>/', $content, $tags);

            foreach ($tags[0] as $tag) {
                if (preg_match('/\s(?:href|:href)=(["\'])((?:(?!\1).)*)\1/', $tag, $attr) && str_contains($attr[2], '/console')) {
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
