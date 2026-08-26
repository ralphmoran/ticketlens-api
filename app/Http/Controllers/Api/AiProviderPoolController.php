<?php

namespace App\Http\Controllers\Api;

use App\Services\ActiveGroupResolver;
use App\Services\AiProviderPoolService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Group-shared AI provider registry — dynamic (any title/endpoint/model), unlike
 * the fixed 3-provider UserAiProvider/AiProviderController. Read is available to
 * any team member (permission:Summarize route group); write is team.manager-only
 * (route group), matching the Members/Seats/Integrations admin pattern.
 */
class AiProviderPoolController
{
    public function __construct(
        private readonly ActiveGroupResolver $groupResolver,
        private readonly AiProviderPoolService $ai,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $group = $this->groupResolver->forRequest($request);
        if (! $group) {
            return response()->json(['providers' => []]);
        }

        $pools = $group->aiProviderPools()->get()->map(fn($p) => $p->toDisplayArray());

        return response()->json(['providers' => $pools]);
    }

    public function store(Request $request): JsonResponse
    {
        $group = $this->groupResolver->forRequest($request);
        if (! $group) {
            return response()->json(['error' => 'No team found for this account.'], 422);
        }

        $validated = $this->validated($request);

        $pool = $group->aiProviderPools()->create([
            ...$validated,
            'created_by' => $request->user()->id,
            // Explicit, not relying on the migration's DB-level default — Eloquent's
            // create() doesn't re-sync DB-computed defaults into the returned instance,
            // so the immediate toDisplayArray() response would otherwise show enabled: null.
            'enabled' => true,
        ]);

        return response()->json($pool->toDisplayArray(), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $pool = $this->findOrFail($request, $id);

        $validated = $request->validate([
            'title'         => ['sometimes', 'string', 'max:100'],
            'provider_type' => ['sometimes', Rule::in(['openai_compatible', 'anthropic'])],
            'api_key'       => ['sometimes', 'string', 'min:10', 'max:500'],
            'endpoint'      => ['sometimes', 'nullable', 'url', 'max:500'],
            'model'         => ['sometimes', 'string', 'max:100'],
            'notes'         => ['sometimes', 'nullable', 'string', 'max:1000'],
            'enabled'       => ['sometimes', 'boolean'],
        ]);

        $pool->update($validated);

        return response()->json($pool->toDisplayArray());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->findOrFail($request, $id)->delete();

        return response()->json(['deleted' => true]);
    }

    public function test(Request $request, int $id): JsonResponse
    {
        $pool = $this->findOrFail($request, $id);

        if (! $pool->enabled) {
            return response()->json(['error' => 'Provider is disabled.'], 422);
        }

        try {
            $result = $this->ai->call($pool, 'Say OK in exactly one word.', 16);
            return response()->json(['ok' => true, 'response' => $result]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Aborts 404 both when the pool row doesn't exist and when the caller has no group at all. */
    private function findOrFail(Request $request, int $id): \App\Models\AiProviderPool
    {
        $group = $this->groupResolver->forRequest($request);
        abort_if(! $group, 404);

        return $group->aiProviderPools()->findOrFail($id);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title'         => ['required', 'string', 'max:100'],
            'provider_type' => ['required', Rule::in(['openai_compatible', 'anthropic'])],
            'api_key'       => ['required', 'string', 'min:10', 'max:500'],
            'endpoint'      => ['required_if:provider_type,openai_compatible', 'nullable', 'url', 'max:500'],
            'model'         => ['required', 'string', 'max:100'],
            'notes'         => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);
    }
}
