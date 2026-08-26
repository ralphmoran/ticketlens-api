<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\NotEnoughProvidersException;
use App\Models\AiProviderRole;
use App\Services\AiConsensusService;
use App\Services\RecallSecretScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Server-side execution for ticket-lens's `ticketlens compliance TICKET --consensus`.
 * Runs here (not client-side) because AiProviderPool keys are encrypted at rest
 * and never sent to the CLI — see AiConsensusService for the actual algorithm,
 * a direct port of the CLI's original local-BYOK implementation.
 */
class ConsensusController
{
    public function __construct(
        private readonly AiConsensusService $consensus,
        private readonly RecallSecretScanner $scanner,
    ) {}

    public function run(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ticketKey'      => ['required', 'string', 'max:50'],
            'diff'           => ['nullable', 'string'],
            'requirements'   => ['required', 'array', 'min:1'],
            'requirements.*' => ['string'],
        ]);

        $diff = $validated['diff'] ?? '';

        // Same gate the CLI's local-BYOK version applied before this endpoint existed —
        // the diff must never reach a third-party AI vendor if it looks like a secret.
        $scan = $this->scanner->scan(['body' => $diff]);
        if ($scan['rejected']) {
            return response()->json([
                'error' => 'Blocked — the diff looks like it contains a secret: ' . implode(' ', $scan['reasons']),
            ], 422);
        }

        $role = $request->user()->aiProviderRoles()
            ->where('kind', AiProviderRole::KIND_CONSENSUS)
            ->with('providers')
            ->first();

        if (! $role) {
            return response()->json([
                'error' => 'No consensus role configured. Set one up in Console > Admin > AI Providers.',
            ], 422);
        }

        $providers = $role->providers->filter(fn($p) => $p->enabled)->values()->all();
        if (count($providers) < 2) {
            return response()->json([
                'error' => 'Your consensus role needs at least 2 enabled providers attached — currently has ' . count($providers) . '.',
            ], 422);
        }

        try {
            $result = $this->consensus->run($providers, $diff, $validated['requirements']);
        } catch (NotEnoughProvidersException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }

        return response()->json([
            'results'        => $result['results'],
            'perAgent'       => $result['perAgent'],
            'disagreedCount' => $result['disagreedCount'],
            'warnings'       => array_merge($scan['warnings'], $result['round2Failures']),
        ]);
    }
}
