<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Signs and POSTs one webhook event. Shared by delivery and by the registration handshake,
 * so both go through the same SSRF guard, address pinning and signature scheme.
 */
class WebhookSender
{
    private const TIMEOUT_SECONDS = 5;

    private const CONNECT_TIMEOUT_SECONDS = 3;

    /** Receivers only need to answer 2xx; the handshake reads a tiny JSON body. */
    public const MAX_RESPONSE_BYTES = 65536;

    public function __construct(private readonly WebhookUrlGuard $guard) {}

    /** @throws \InvalidArgumentException when the URL fails the SSRF guard */
    public function send(string $url, string $secret, string $event, string $deliveryId, array $data): Response
    {
        // Re-resolved on every send: a host that rebinds to a private address after registration is refused.
        $target = $this->guard->resolve($url);

        $body      = json_encode([
            'id'         => $deliveryId,
            'event'      => $event,
            'created_at' => now()->toIso8601String(),
            'data'       => $data,
        ], JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->timestamp;

        return Http::timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->withHeaders([
                'TicketLens-Event'     => $event,
                'TicketLens-Delivery'  => $deliveryId,
                'TicketLens-Timestamp' => $timestamp,
                'TicketLens-Signature' => 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret),
            ])
            ->withOptions($this->requestOptions($target))
            ->withBody($body, 'application/json')
            ->post($url);
    }

    /**
     * @param  array{host: string, ips: array<int, string>}  $target
     * @return array<string, mixed>
     */
    public function requestOptions(array $target): array
    {
        return [
            'allow_redirects' => false,
            // Refuse to buffer a huge body into memory on the synchronous handshake path: abort early on a
            // declared length, and on bytes actually received (chunked or lying responses have no usable length).
            'on_headers'      => function (ResponseInterface $response): void {
                if ((int) $response->getHeaderLine('Content-Length') > self::MAX_RESPONSE_BYTES) {
                    throw new RuntimeException('Webhook response too large.');
                }
            },
            'progress'        => function (int|float $downloadTotal, int|float $downloaded): void {
                if ($downloaded > self::MAX_RESPONSE_BYTES) {
                    throw new RuntimeException('Webhook response too large.');
                }
            },
            // A proxy would resolve the host itself and defeat the pinned addresses below.
            'proxy'           => '',
            // Connect to the addresses just validated, not whatever DNS says a moment later.
            'curl'            => [CURLOPT_RESOLVE => array_map(fn (string $ip) => "{$target['host']}:443:{$ip}", $target['ips'])],
        ];
    }
}
