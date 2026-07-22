<?php

namespace App\Services;

class SlackMrkdwnEscaper
{
    /**
     * Neutralize Slack mrkdwn control characters in user-controlled text
     * before it's interpolated into a `chat.postMessage` text body — closes
     * mass-ping (`<!channel>`/`<!here>`/`<@USERID>`) and link-spoofing
     * (`<url|text>`) injection. Order matters: `&` must escape first, or the
     * `&lt;`/`&gt;` this method produces would itself get double-encoded.
     */
    public function escape(string $text): string
    {
        $text = str_replace('&', '&amp;', $text);
        $text = str_replace('<', '&lt;', $text);
        return str_replace('>', '&gt;', $text);
    }
}
