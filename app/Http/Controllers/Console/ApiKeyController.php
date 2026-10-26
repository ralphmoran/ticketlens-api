<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ApiKeyController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Console/ApiKeys', [
            'keys' => $request->user()->apiKeys()->latest()->get()->map(fn (ApiKey $key) => [
                'id'           => $key->id,
                'name'         => $key->name,
                'key_prefix'   => $key->key_prefix,
                'scopes'       => $key->scopes,
                'last_used_at' => $key->last_used_at?->toIso8601String(),
                'revoked_at'   => $key->revoked_at?->toIso8601String(),
                'created_at'   => $key->created_at->toIso8601String(),
            ]),
            'availableScopes' => ApiKey::SCOPES,
            'maxActiveKeys'   => ApiKey::MAX_ACTIVE_PER_USER,
            'webhooks'        => $request->user()->webhookEndpoints()->latest()->get()->map(fn (WebhookEndpoint $endpoint) => [
                'id'                => $endpoint->id,
                'url'               => $endpoint->url,
                'failure_count'     => $endpoint->failure_count,
                'last_delivered_at' => $endpoint->last_delivered_at?->toIso8601String(),
                'disabled_at'       => $endpoint->disabled_at?->toIso8601String(),
                'created_at'        => $endpoint->created_at->toIso8601String(),
            ]),
            'maxWebhooks'     => WebhookEndpoint::MAX_PER_USER,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name'     => ['required', 'string', 'max:100'],
            'scopes'   => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(ApiKey::SCOPES)],
        ]);

        $plaintext = 'tl_pk_' . Str::random(40);

        // Row lock serialises concurrent creates so the active-key cap cannot be raced.
        DB::transaction(function () use ($user, $data, $plaintext) {
            User::whereKey($user->id)->lockForUpdate()->first();

            if ($user->apiKeys()->active()->count() >= ApiKey::MAX_ACTIVE_PER_USER) {
                throw ValidationException::withMessages([
                    'name' => 'You can have at most ' . ApiKey::MAX_ACTIVE_PER_USER . ' active API keys. Revoke one first.',
                ]);
            }

            $user->apiKeys()->create([
                'name'       => $data['name'],
                'key_prefix' => substr($plaintext, 0, ApiKey::PREFIX_LENGTH),
                'key_hash'   => ApiKey::hashToken($plaintext),
                'scopes'     => array_values(array_unique($data['scopes'])),
            ]);
        });

        // Flash plaintext once — never persisted
        return redirect()->back()->with('api_key_generated', $plaintext);
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $key = $request->user()->apiKeys()->active()->findOrFail($id);
        $key->update(['revoked_at' => now()]);

        return redirect()->back()->with('success', 'API key revoked.');
    }
}
