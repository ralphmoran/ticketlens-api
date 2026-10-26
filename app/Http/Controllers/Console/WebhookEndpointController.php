<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\AuditService;
use App\Services\WebhookUrlGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class WebhookEndpointController extends Controller
{
    public function __construct(
        private readonly WebhookUrlGuard $guard,
        private readonly AuditService $audit,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate(['url' => ['required', 'string', 'max:2048']]);

        try {
            $this->guard->resolve($data['url']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        }

        $secret = 'whsec_' . Str::random(40);

        // Row lock serialises concurrent creates so the endpoint cap cannot be raced.
        $endpoint = DB::transaction(function () use ($user, $data, $secret) {
            User::whereKey($user->id)->lockForUpdate()->first();

            if ($user->webhookEndpoints()->count() >= WebhookEndpoint::MAX_PER_USER) {
                throw ValidationException::withMessages([
                    'url' => 'You can have at most ' . WebhookEndpoint::MAX_PER_USER . ' webhook endpoints. Delete one first.',
                ]);
            }

            return $user->webhookEndpoints()->create(['url' => $data['url'], 'secret' => $secret]);
        });

        $this->audit->logFromRequest($request, 'webhook_endpoint.created', null, null, null, ['endpoint_id' => $endpoint->id]);

        // Flash the signing secret once; it is only ever shown here.
        return redirect()->back()->with('webhook_secret_generated', $secret);
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $endpoint = $request->user()->webhookEndpoints()->findOrFail($id);
        $endpoint->delete();

        $this->audit->logFromRequest($request, 'webhook_endpoint.deleted', null, null, null, ['endpoint_id' => $id]);

        return redirect()->back()->with('success', 'Webhook endpoint deleted.');
    }
}
