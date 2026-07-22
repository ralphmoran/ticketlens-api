<?php

namespace Tests\Unit\Services;

use App\Services\SlackMrkdwnEscaper;
use PHPUnit\Framework\TestCase;

class SlackMrkdwnEscaperTest extends TestCase
{
    private SlackMrkdwnEscaper $escaper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->escaper = new SlackMrkdwnEscaper();
    }

    public function test_plain_text_passes_through_unchanged(): void
    {
        $this->assertSame('Fix login page', $this->escaper->escape('Fix login page'));
    }

    public function test_neutralizes_channel_mass_ping(): void
    {
        $this->assertSame('&lt;!channel&gt;', $this->escaper->escape('<!channel>'));
    }

    public function test_neutralizes_here_mass_ping(): void
    {
        $this->assertSame('&lt;!here&gt;', $this->escaper->escape('<!here>'));
    }

    public function test_neutralizes_user_mention(): void
    {
        $this->assertSame('&lt;@U12345&gt;', $this->escaper->escape('<@U12345>'));
    }

    public function test_neutralizes_link_spoofing(): void
    {
        $this->assertSame(
            '&lt;https://evil.example|PROJ-123&gt;',
            $this->escaper->escape('<https://evil.example|PROJ-123>'),
        );
    }

    public function test_escapes_ampersand_before_angle_brackets_not_after(): void
    {
        // & must escape first — escaping < / > first would double-encode the &lt;/&gt; it produces.
        $this->assertSame('&amp;lt;', $this->escaper->escape('&lt;'));
    }

    public function test_empty_string_passes_through(): void
    {
        $this->assertSame('', $this->escaper->escape(''));
    }
}
