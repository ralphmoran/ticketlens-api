<?php

namespace App\Http\Controllers\Api;

use App\Services\AiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiProviderController
{
    public function index(Request $request): JsonResponse
    {
        $providers = $request->user()->aiProviders()->get()
            ->map(fn($provider) => $provider->toDisplayArray());

        return response()->json(['providers' => $providers]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider'        => ['required', 'string', 'in:groq,anthropic,openai'],
            'api_key'         => ['required', 'string', 'min:10', 'max:500'],
            'timeout_seconds' => ['sometimes', 'integer', 'min:1', 'max:60'],
        ]);

        $providers = $request->user()->aiProviders();

        $provider = $providers->updateOrCreate(
            ['provider' => $validated['provider']],
            [
                'api_key'         => $validated['api_key'],
                'timeout_seconds' => $validated['timeout_seconds'] ?? 5,
                'priority'        => $providers->count() + 1,
                'enabled'         => true,
            ]
        );

        return response()->json(
            $provider->toDisplayArray(),
            $provider->wasRecentlyCreated ? 201 : 200
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $provider = $request->user()->aiProviders()->findOrFail($id);

        $validated = $request->validate([
            'priority'        => ['sometimes', 'integer', 'min:1'],
            'timeout_seconds' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'enabled'         => ['sometimes', 'boolean'],
        ]);

        $provider->update($validated);

        return response()->json($provider->toDisplayArray());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $request->user()->aiProviders()->findOrFail($id)->delete();

        return response()->json(['deleted' => true]);
    }

    public function test(Request $request, int $id, AiService $ai): JsonResponse
    {
        $provider = $request->user()->aiProviders()->findOrFail($id);

        if (! $provider->enabled) {
            return response()->json(['error' => 'Provider is disabled.'], 422);
        }

        try {
            // Minimal fixed prompt — never echoes the user's data
            $result = $ai->testProvider($provider);
            return response()->json(['ok' => true, 'response' => $result]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
