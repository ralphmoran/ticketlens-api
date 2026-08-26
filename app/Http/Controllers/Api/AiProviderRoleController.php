<?php

namespace App\Http\Controllers\Api;

use App\Models\AiProviderRole;
use App\Services\ActiveGroupResolver;
use App\Services\AiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Personal role -> provider assignment. Providers are drawn from the caller's
 * group-shared AiProviderPool (AiProviderPoolController) but which providers
 * are attached to which role, and in what priority order, is per-user — two
 * teammates can point the same shared "Codex" pool entry at different roles.
 */
class AiProviderRoleController
{
    public function __construct(
        private readonly ActiveGroupResolver $groupResolver,
        private readonly AiService $legacyAi,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $roles = $request->user()->aiProviderRoles()->with('providers')->get()
            ->map(fn($r) => $r->toDisplayArray());

        return response()->json(['roles' => $roles]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'kind'  => ['sometimes', Rule::in(AiProviderRole::KINDS)],
        ]);
        $kind = $validated['kind'] ?? AiProviderRole::KIND_CUSTOM;

        if ($kind === AiProviderRole::KIND_CONSENSUS
            && $request->user()->aiProviderRoles()->where('kind', AiProviderRole::KIND_CONSENSUS)->exists()) {
            return response()->json(['error' => 'A consensus role already exists — edit it instead of creating another.'], 422);
        }

        $role = $request->user()->aiProviderRoles()->create(['label' => $validated['label'], 'kind' => $kind]);

        return response()->json($role->toDisplayArray(), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $role = $request->user()->aiProviderRoles()->findOrFail($id);

        $validated = $request->validate([
            'label'            => ['sometimes', 'string', 'max:100'],
            'generated_prompt' => ['sometimes', 'nullable', 'string', 'max:4000'],
        ]);

        $role->update($validated);

        return response()->json($role->fresh()->load('providers')->toDisplayArray());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $request->user()->aiProviderRoles()->findOrFail($id)->delete();

        return response()->json(['deleted' => true]);
    }

    /** Body: { provider_ids: [3, 1, 2] } — array order becomes fallback priority (1-indexed, main first). */
    public function syncProviders(Request $request, int $id): JsonResponse
    {
        $role = $request->user()->aiProviderRoles()->findOrFail($id);
        $validated = $request->validate([
            'provider_ids'   => ['required', 'array'],
            'provider_ids.*' => ['integer'],
        ]);

        $group = $this->groupResolver->forRequest($request);
        $ownIds = $group ? $group->aiProviderPools()->whereIn('id', $validated['provider_ids'])->pluck('id')->all() : [];

        $sync = [];
        $priority = 1;
        foreach ($validated['provider_ids'] as $poolId) {
            if (in_array($poolId, $ownIds, true)) {
                $sync[$poolId] = ['priority' => $priority++];
            }
        }
        $role->providers()->sync($sync);

        return response()->json($role->fresh()->load('providers')->toDisplayArray());
    }

    /**
     * Auto-drafts a system prompt from the role's label, using the caller's own
     * legacy UserAiProvider (the pre-existing summarize/handoff BYOK) as the
     * "smarter model" — no platform-owned AI key exists anywhere in this app,
     * so generation is bootstrapped off whatever the user already has working.
     * The result is a starting draft, not final: the caller edits it via
     * update()'s generated_prompt field before it's ever used for real.
     */
    public function generatePrompt(Request $request, int $id): JsonResponse
    {
        $role = $request->user()->aiProviderRoles()->findOrFail($id);

        $metaPrompt = 'Write a concise system prompt (2-4 sentences) for an AI assistant serving this role '
            . "in a software developer's workflow: \"{$role->label}\". The prompt will instruct another AI "
            . 'model on its responsibilities, scope, and expected output style. Return ONLY the system '
            . 'prompt text itself — no preamble, no quotes, no explanation.';

        try {
            // 1024, not the 256 default — reasoning models (e.g. Groq's
            // openai/gpt-oss-120b) can burn the whole budget on hidden reasoning
            // before any visible answer, leaving content empty on a 200 response.
            $generated = trim($this->legacyAi->generateText($request->user(), $metaPrompt, maxTokens: 1024));
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Could not generate a prompt: ' . $e->getMessage()], 422);
        }

        // A 200 response with empty content is a real (if now rarer) outcome for
        // reasoning models that exhaust their budget on hidden reasoning — must
        // never look like success (prompt_generated_at set, textarea silently
        // empty). Treat it the same as any other generation failure.
        if ($generated === '') {
            return response()->json(['error' => 'Could not generate a prompt: the model returned an empty response. Try again.'], 422);
        }

        $role->update(['generated_prompt' => $generated, 'prompt_generated_at' => now()]);

        return response()->json($role->fresh()->load('providers')->toDisplayArray());
    }
}
