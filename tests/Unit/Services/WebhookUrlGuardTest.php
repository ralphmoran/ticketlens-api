<?php

namespace Tests\Unit\Services;

use App\Services\WebhookUrlGuard;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WebhookUrlGuardTest extends TestCase
{
    private function guard(array $ips = ['93.184.216.34']): WebhookUrlGuard
    {
        return new WebhookUrlGuard(fn (string $host) => $ips);
    }

    public function test_accepts_https_url_resolving_to_public_ips_and_returns_them(): void
    {
        $target = $this->guard(['93.184.216.34'])->resolve('https://hooks.example.com/ticketlens');

        $this->assertSame('hooks.example.com', $target['host']);
        $this->assertSame(['93.184.216.34'], $target['ips']);
    }

    #[DataProvider('unsafeUrls')]
    public function test_rejects_unsafe_urls(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->guard()->resolve($url);
    }

    public static function unsafeUrls(): array
    {
        return [
            'plain http'        => ['http://hooks.example.com/x'],
            'ftp scheme'        => ['ftp://hooks.example.com/x'],
            'no scheme'         => ['hooks.example.com/x'],
            'userinfo'          => ['https://user:pass@hooks.example.com/x'],
            'custom port'       => ['https://hooks.example.com:8443/x'],
            'ipv4 literal'      => ['https://93.184.216.34/x'],
            'loopback literal'  => ['https://127.0.0.1/x'],
            'ipv6 literal'      => ['https://[::1]/x'],
            'garbage'           => ['https://'],
        ];
    }

    #[DataProvider('nonPublicAddresses')]
    public function test_rejects_hosts_resolving_to_non_public_addresses(string $ip): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->guard([$ip])->resolve('https://hooks.example.com/x');
    }

    public static function nonPublicAddresses(): array
    {
        return [
            'loopback'     => ['127.0.0.1'],
            'private 10'   => ['10.0.0.5'],
            'private 192'  => ['192.168.1.10'],
            'private 172'  => ['172.16.0.1'],
            'link local'   => ['169.254.169.254'],
            'reserved'     => ['0.0.0.0'],
            'cgnat'        => ['100.100.100.200'],
            'benchmarking' => ['198.18.0.1'],
            'ietf protocol' => ['192.0.0.8'],
        ];
    }

    public function test_rejects_when_any_resolved_address_is_non_public(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->guard(['93.184.216.34', '10.0.0.5'])->resolve('https://hooks.example.com/x');
    }

    public function test_rejects_unresolvable_host(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->guard([])->resolve('https://nope.example.com/x');
    }
}
