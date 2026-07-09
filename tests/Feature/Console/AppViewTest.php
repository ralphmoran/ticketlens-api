<?php

namespace Tests\Feature\Console;

use Tests\TestCase;

/**
 * Locks the app.blade.php <head> contract: both CSP-blocked inline
 * constructs (audit §3.9) live in an external, script-src 'self'-compliant
 * file, not inline. Behavior (theme-FOUC set-before-paint, font
 * preload→stylesheet promotion) is unchanged — only where the JS lives.
 */
class AppViewTest extends TestCase
{
    public function test_head_has_no_inline_theme_script(): void
    {
        $html = $this->get('/console/login')->getContent();

        $this->assertStringNotContainsString('localStorage.getItem', $html);
    }

    public function test_font_preload_link_has_no_inline_onload_attribute(): void
    {
        $html = $this->get('/console/login')->getContent();

        $this->assertStringNotContainsString('onload=', $html);
    }

    public function test_theme_init_script_tag_present(): void
    {
        $html = $this->get('/console/login')->getContent();

        $this->assertStringContainsString('/js/theme-init.js', $html);
    }

    public function test_font_preload_link_has_id_for_external_script(): void
    {
        $html = $this->get('/console/login')->getContent();

        $this->assertStringContainsString('id="tl-font-preload"', $html);
    }

    public function test_page_still_renders_when_theme_init_script_is_missing(): void
    {
        $path = public_path('js/theme-init.js');
        $backup = $path . '.bak';
        rename($path, $backup);

        try {
            $this->get('/console/login')->assertOk();
        } finally {
            rename($backup, $path);
        }
    }
}
