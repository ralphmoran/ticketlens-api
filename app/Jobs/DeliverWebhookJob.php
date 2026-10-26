<?php

namespace App\Jobs;

use App\Models\ApiKey;
use App\Models\WebhookEndpoint;
use App\Services\WebhookSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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

    public function handle(WebhookSender $sender): void
    {
        $endpoint = WebhookEndpoint::with('user')->find($this->endpointId);

        if (! $endpoint || $endpoint->disabled_at || ! $this->ownerEntitled($endpoint)) {
            return;
        }

        try {
            $response = $sender->send($endpoint->url, $endpoint->secret, $this->event, $this->deliveryId, $this->data);
        } catch (InvalidArgumentException $e) {
            // Throw, not fail fast: a transient DNS miss must retry, and only exhausted attempts charge the endpoint.
            throw new RuntimeException("Webhook endpoint {$endpoint->id} refused: {$e->getMessage()}", 0, $e);
        }

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
