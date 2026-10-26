<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\AuditService;
use App\Services\WebhookSender;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class WebhookEndpointController extends Controller
{
    public function __construct(
        private readonly WebhookSender $sender,
        private readonly AuditService $audit,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate(['url' => ['required', 'string', 'max:2048']]);

        if ($user->webhookEndpoints()->count() >= WebhookEndpoint::MAX_PER_USER) {
            throw ValidationException::withMessages(['url' => $this->capMessage()]);
        }

        $secret = 'whsec_' . Str::random(40);

        $this->verifyOwnership($data['url'], $secret);

        // Row lock serialises concurrent creates so the endpoint cap cannot be raced.
        $endpoint = DB::transaction(function () use ($user, $data, $secret) {
            User::whereKey($user->id)->lockForUpdate()->first();

            if ($user->webhookEndpoints()->count() >= WebhookEndpoint::MAX_PER_USER) {
                throw ValidationException::withMessages(['url' => $this->capMessage()]);
            }

            return $user->webhookEndpoints()->create(['url' => $data['url'], 'secret' => $secret]);
        });

        $this->audit->logFromRequest($request, 'webhook_endpoint.created', null, null, null, ['endpoint_id' => $endpoint->id]);

        // Flash the signing secret once; it is only ever shown here.
        return redirect()->back()->with('webhook_secret_generated', $secret);
    }

    public function enable(Request $request, int $id): RedirectResponse
    {
        $endpoint = $request->user()->webhookEndpoints()->findOrFail($id);

        // Ownership is proven again: the domain may have changed hands while the endpoint was disabled.
        $this->verifyOwnership($endpoint->url, $endpoint->secret, 'enable');
        $endpoint->update(['disabled_at' => null, 'failure_count' => 0]);

        $this->audit->logFromRequest($request, 'webhook_endpoint.enabled', null, null, null, ['endpoint_id' => $id]);

        return redirect()->back()->with('success', 'Webhook endpoint re-enabled.');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $endpoint = $request->user()->webhookEndpoints()->findOrFail($id);
        $endpoint->delete();

        $this->audit->logFromRequest($request, 'webhook_endpoint.deleted', null, null, null, ['endpoint_id' => $id]);

        return redirect()->back()->with('success', 'Webhook endpoint deleted.');
    }

    /**
     * Handshake: the receiver must echo a random challenge from a signed `webhook.verify` event,
     * so an account cannot aim deliveries at a third party that never agreed to receive them.
     */
    private function verifyOwnership(string $url, string $secret, string $errorKey = 'url'): void
    {
        $challenge = Str::random(40);

        try {
            $response = $this->sender->send($url, $secret, 'webhook.verify', (string) Str::uuid(), ['challenge' => $challenge]);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([$errorKey => $e->getMessage()]);
        } catch (ConnectionException|TransferException) {
            throw ValidationException::withMessages([$errorKey => 'Could not reach the endpoint to verify it.']);
        }

        if (! $response->successful() || ! hash_equals($challenge, (string) $response->json('challenge'))) {
            throw ValidationException::withMessages([
                $errorKey => 'The endpoint must answer 2xx with JSON {"challenge": "<the challenge it received>"}.',
            ]);
        }
    }

    private function capMessage(): string
    {
        return 'You can have at most ' . WebhookEndpoint::MAX_PER_USER . ' webhook endpoints. Delete one first.';
    }
}
