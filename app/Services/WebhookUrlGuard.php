<?php

namespace App\Services;

use Closure;
use InvalidArgumentException;

/**
 * SSRF guard for webhook targets: https on 443, a hostname (never an IP literal),
 * and every resolved IPv4 address public. Called at registration and again at send time.
 */
class WebhookUrlGuard
{
    /** Ranges PHP's NO_PRIV/NO_RES flags miss: CGNAT (cloud metadata), IETF protocol, benchmarking. */
    private const EXTRA_BLOCKED_CIDRS = ['100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15'];

    /** @param (Closure(string): (array<int, string>|false))|null $resolver host → IPv4 list; defaults to DNS */
    public function __construct(private readonly ?Closure $resolver = null) {}

    /** @return array{host: string, ips: array<int, string>} */
    public function resolve(string $url): array
    {
        $parts = parse_url($url);
        $host  = is_array($parts) ? ($parts['host'] ?? '') : '';

        if (($parts['scheme'] ?? '') !== 'https' || $host === '') {
            throw new InvalidArgumentException('Webhook URL must be an https URL.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Webhook URL must not contain credentials.');
        }

        if (isset($parts['port']) && $parts['port'] !== 443) {
            throw new InvalidArgumentException('Webhook URL must use port 443.');
        }

        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            throw new InvalidArgumentException('Webhook URL must use a hostname, not an IP address.');
        }

        $ips = ($this->resolver ?? static fn (string $h) => gethostbynamel($h))($host);

        if (! $ips) {
            throw new InvalidArgumentException('Webhook host does not resolve.');
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
                || $this->inBlockedRange($ip)) {
                throw new InvalidArgumentException('Webhook host resolves to a private or reserved address.');
            }
        }

        return ['host' => $host, 'ips' => array_values($ips)];
    }

    private function inBlockedRange(string $ip): bool
    {
        $long = ip2long($ip);

        foreach (self::EXTRA_BLOCKED_CIDRS as $cidr) {
            [$base, $bits] = explode('/', $cidr);
            $mask = -1 << (32 - (int) $bits);

            if (($long & $mask) === (ip2long($base) & $mask)) {
                return true;
            }
        }

        return false;
    }
}
