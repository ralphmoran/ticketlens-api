<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Locks backlog #36: a Console nav click on the page already shown must not
 * re-request it. Each nav <Link> carries `:on-before="skipSameUrl"`, which
 * cancels a same-URL GET visit. A cancelled visit never fires Inertia's
 * `start` event, so the guard is built with `handleNavStart` to keep the
 * mobile drawer closing. Behaviour lives in composables/sameUrl.js and is
 * unit-tested in tests/js/sameUrl.test.mjs (run here so CI covers it).
 */
class ConsoleNavLinksSkipSameUrlTest extends TestCase
{
    private const START_HANDLER = '@start="handleNavStart"';
    private const GUARD_ATTRIBUTE = ':on-before="skipSameUrl"';

    public function test_sidebar_nav_links_still_close_the_drawer_on_start(): void
    {
        $tags = $this->sidebarNavLinkTags($this->read('Layouts/ConsoleLayout.vue'));

        $this->assertGreaterThanOrEqual(7, count($tags), 'Expected the 7 sidebar nav Links (class tl-nav-link / tl-float-item)');
        $this->assertSame([], $this->tagsMissing($tags, self::START_HANDLER), 'Sidebar nav Links missing ' . self::START_HANDLER);
    }

    public function test_every_sidebar_nav_link_skips_same_url_visits(): void
    {
        $tags = $this->sidebarNavLinkTags($this->read('Layouts/ConsoleLayout.vue'));

        $this->assertNotEmpty($tags, 'ConsoleLayout must render sidebar nav Links');
        $this->assertSame([], $this->tagsMissing($tags, self::GUARD_ATTRIBUTE), 'Sidebar nav Links missing ' . self::GUARD_ATTRIBUTE);
    }

    public function test_header_settings_gear_links_skip_same_url_visits(): void
    {
        $gears = array_filter(
            $this->linkTags($this->read('Layouts/ConsoleLayout.vue')),
            fn (string $tag) => str_contains($tag, 'title="Settings"'),
        );

        $this->assertGreaterThanOrEqual(2, count($gears), 'Expected the mobile and desktop header Settings gear Links');
        $this->assertSame([], $this->tagsMissing(array_values($gears), self::GUARD_ATTRIBUTE), 'Settings gear Links missing ' . self::GUARD_ATTRIBUTE);
    }

    public function test_layout_builds_the_guard_with_the_drawer_close_handler(): void
    {
        $layout = $this->read('Layouts/ConsoleLayout.vue');

        $this->assertStringContainsString('const skipSameUrl = useSkipSameUrl(handleNavStart)', $layout);
    }

    public function test_every_settings_tab_link_skips_same_url_visits(): void
    {
        $source = $this->read('components/TlSettingsTabs.vue');
        $tags = $this->linkTags($this->templateOf($source));

        $this->assertNotEmpty($tags, 'TlSettingsTabs must render <Link> tabs');
        $this->assertSame([], $this->tagsMissing($tags, self::GUARD_ATTRIBUTE), 'Settings tab Links missing ' . self::GUARD_ATTRIBUTE);
        $this->assertStringContainsString('const skipSameUrl = useSkipSameUrl()', $source);
    }

    public function test_same_url_guard_js_unit_tests_pass(): void
    {
        $node = (new ExecutableFinder())->find('node');
        if ($node === null && getenv('CI')) {
            $this->fail('node is not on PATH in CI; tests/js/sameUrl.test.mjs did not run.');
        }
        if ($node === null) {
            $this->markTestSkipped('node is not on PATH; tests/js/sameUrl.test.mjs was not run.');
        }

        $basePath = dirname(__DIR__, 2);
        $process = new Process([$node, '--test', 'tests/js/sameUrl.test.mjs'], $basePath);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
    }

    /**
     * Sidebar nav Links are identified by their class, not by the attributes
     * under test, so a new Link that forgets both the handler and the guard fails.
     *
     * @return list<string>
     */
    private function sidebarNavLinkTags(string $source): array
    {
        return array_values(array_filter(
            $this->linkTags($source),
            fn (string $tag) => (bool) preg_match('/\sclass="(?:tl-nav-link|tl-float-item)\b/', $tag),
        ));
    }

    /**
     * Whole opening tags, quote-aware so a `>` inside an attribute value
     * (an arrow function in :class, a `count > 0` in v-if) does not end the tag early.
     *
     * @return list<string>
     */
    private function linkTags(string $source): array
    {
        preg_match_all('/<Link\b(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>/', $source, $matches);

        return $matches[0];
    }

    /**
     * @param  list<string>  $tags
     * @return list<string>
     */
    private function tagsMissing(array $tags, string $needle): array
    {
        return array_values(array_filter($tags, fn (string $tag) => !str_contains($tag, $needle)));
    }

    // Comments in <script> may mention <Link>; only the template renders tags.
    private function templateOf(string $source): string
    {
        preg_match('/<template>.*<\/template>/s', $source, $match);

        return $match[0] ?? '';
    }

    private function read(string $relativePath): string
    {
        return file_get_contents(dirname(__DIR__, 2) . '/resources/js/' . $relativePath);
    }
}
