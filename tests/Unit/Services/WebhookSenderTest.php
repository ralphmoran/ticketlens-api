<?php

namespace Tests\Unit\Services;

use App\Services\WebhookSender;
use App\Services\WebhookUrlGuard;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class WebhookSenderTest extends TestCase
{
    public function test_request_options_pin_validated_addresses_and_bypass_proxies_and_redirects(): void
    {
        $options = (new WebhookSender(new WebhookUrlGuard()))->requestOptions([
            'host' => 'hooks.example.com',
            'ips'  => ['93.184.216.34', '93.184.216.35'],
        ]);

        $this->assertSame(
            ['hooks.example.com:443:93.184.216.34', 'hooks.example.com:443:93.184.216.35'],
            $options['curl'][CURLOPT_RESOLVE],
            'curl must connect to the addresses the guard validated, not re-resolve',
        );
        $this->assertSame('', $options['proxy'], 'a proxy would resolve the host itself and defeat the pin');
        $this->assertFalse($options['allow_redirects']);
    }

    public function test_oversized_response_is_aborted_from_its_headers(): void
    {
        $options = (new WebhookSender(new WebhookUrlGuard()))->requestOptions(['host' => 'h.example.com', 'ips' => ['93.184.216.34']]);

        $this->expectException(\RuntimeException::class);

        $options['on_headers'](new Response(200, ['Content-Length' => (string) (WebhookSender::MAX_RESPONSE_BYTES + 1)]));
    }

    public function test_response_within_the_cap_is_allowed(): void
    {
        $options = (new WebhookSender(new WebhookUrlGuard()))->requestOptions(['host' => 'h.example.com', 'ips' => ['93.184.216.34']]);

        $options['on_headers'](new Response(200, ['Content-Length' => '120']));

        $this->addToAssertionCount(1);
    }
}
