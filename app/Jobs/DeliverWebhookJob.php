<?php

namespace App\Jobs;

use App\Models\ApiKey;
use App\Models\WebhookEndpoint;
use App\Services\WebhookUrlGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Delivers one signed event to one endpoint. A non-2xx answer throws so the queue retries;
 * the endpoint is only charged a failure once every attempt is exhausted.
 */
class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 30;

    private const TIMEOUT_SECONDS = 5;

    public function __construct(
        private readonly int    $endpointId,
        private readonly string $event,
        private readonly array  $data,
        private readonly string $deliveryId,
    ) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 60, 300, 1800];
    }

    public function handle(WebhookUrlGuard $guard): void
    {
        $endpoint = WebhookEndpoint::with('user')->find($this->endpointId);

        if (! $endpoint || $endpoint->disabled_at || ! $this->ownerEntitled($endpoint)) {
            return;
        }

        // Re-resolved on every attempt: a host that rebinds to a private address after registration is refused.
        try {
            $target = $guard->resolve($endpoint->url);
        } catch (InvalidArgumentException $e) {
            // Throw, not fail fast: a transient DNS miss must retry, and only exhausted attempts charge the endpoint.
            throw new RuntimeException("Webhook endpoint {$endpoint->id} refused: {$e->getMessage()}", 0, $e);
        }

        $body      = json_encode([
            'id'         => $this->deliveryId,
            'event'      => $this->event,
            'created_at' => now()->toIso8601String(),
            'data'       => $this->data,
        ], JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->timestamp;

        $response = Http::timeout(self::TIMEOUT_SECONDS)
            ->withHeaders([
                'TicketLens-Event'     => $this->event,
                'TicketLens-Delivery'  => $this->deliveryId,
                'TicketLens-Timestamp' => $timestamp,
                'TicketLens-Signature' => 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $endpoint->secret),
            ])
            ->withOptions([
                'allow_redirects' => false,
                // A proxy would resolve the host itself and defeat the pinned addresses below.
                'proxy'           => '',
                // Connect to the addresses just validated, not whatever DNS says a moment later.
                'curl' => [CURLOPT_RESOLVE => array_map(fn (string $ip) => "{$target['host']}:443:{$ip}", $target['ips'])],
            ])
            ->withBody($body, 'application/json')
            ->post($endpoint->url);

        if (! $response->successful()) {
            throw new RuntimeException("Webhook endpoint {$endpoint->id} answered {$response->status()}.");
        }

        $endpoint->recordSuccess();
    }

    public function failed(Throwable $e): void
    {
        WebhookEndpoint::find($this->endpointId)?->recordFailure();
    }

    private function ownerEntitled(WebhookEndpoint $endpoint): bool
    {
        $user = $endpoint->user;

        return $user && ! $user->suspended_at && ApiKey::entitles($user);
    }
}
