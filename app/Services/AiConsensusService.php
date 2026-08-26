<?php

namespace App\Services;

use App\Exceptions\NotEnoughProvidersException;
use App\Models\AiProviderPool;

/**
 * Server-side port of ticket-lens's consensus-checker.mjs — same two-round
 * refinement + majority-vote algorithm, now running here because pool provider
 * keys are encrypted server-side and never sent to the CLI. Behavioral parity
 * with the CLI's original local-BYOK implementation is intentional, not
 * coincidental — same prompts, same tie-break rule, same response format.
 */
class AiConsensusService
{
    private const MAX_TOKENS = 512;
    private const TOKENS_PER_REQUIREMENT = 40;
    // Reasoning models (e.g. Groq's openai/gpt-oss-120b) spend tokens on a hidden
    // reasoning trace before the visible per-requirement verdict list, counted
    // against the same budget — confirmed live via AiService::generateText's
    // equivalent bug (finish_reason: "length", empty content on a 200). This is
    // on top of the per-requirement budget below, not instead of it.
    private const REASONING_TOKEN_ALLOWANCE = 512;

    /** Lower = stricter (less coverage claimed). Drives the tie-break in reconcile(). */
    private const STRICTNESS = ['NOT_FOUND' => 0, 'PARTIAL' => 1, 'FOUND' => 2];

    public function __construct(private readonly AiProviderPoolService $ai) {}

    /**
     * @param AiProviderPool[] $providers
     * @param string[] $requirements
     * @return array{results: array, perAgent: array, disagreedCount: int}
     */
    public function run(array $providers, string $diff, array $requirements): array
    {
        $round1Prompt = $this->buildRound1Prompt($diff, $requirements);
        $round1MaxTokens = self::REASONING_TOKEN_ALLOWANCE + max(self::MAX_TOKENS, count($requirements) * self::TOKENS_PER_REQUIREMENT);

        $round1 = [];
        foreach ($providers as $provider) {
            try {
                $raw = $this->ai->call($provider, $round1Prompt, $round1MaxTokens);
                $round1[] = ['provider' => $provider, 'verdicts' => $this->parseVerdicts($requirements, $raw), 'error' => null];
            } catch (\Throwable $e) {
                $round1[] = ['provider' => $provider, 'verdicts' => null, 'error' => $e->getMessage()];
            }
        }

        $successful1 = array_values(array_filter($round1, fn($r) => $r['error'] === null));
        if (count($successful1) < 2) {
            $errors = array_map(fn($r) => "{$r['provider']->title}: {$r['error']}", array_filter($round1, fn($r) => $r['error'] !== null));
            throw new NotEnoughProvidersException(count($successful1), array_values($errors));
        }

        $disagreedIndexes = [];
        foreach ($requirements as $i => $_) {
            $verdictsAtI = array_map(fn($r) => $r['verdicts'][$i], $successful1);
            if (count(array_unique($verdictsAtI)) > 1) {
                $disagreedIndexes[] = $i;
            }
        }

        $round2ById = [];
        $round2Failures = [];
        if (count($disagreedIndexes) > 0) {
            $disagreedItems = array_map(fn($i) => ['requirement' => $requirements[$i], 'index' => $i], $disagreedIndexes);
            $round2MaxTokens = self::REASONING_TOKEN_ALLOWANCE + max(self::MAX_TOKENS, count($disagreedItems) * self::TOKENS_PER_REQUIREMENT);

            foreach ($successful1 as $r1) {
                $provider = $r1['provider'];
                $prompt = $this->buildRound2Prompt($diff, $disagreedItems, $provider->id, $successful1);
                try {
                    $raw = $this->ai->call($provider, $prompt, $round2MaxTokens);
                    $refinedList = $this->parseVerdicts(array_column($disagreedItems, 'requirement'), $raw);
                    $refined = [];
                    foreach ($disagreedItems as $idx => $item) {
                        $refined[$item['index']] = $refinedList[$idx];
                    }
                    $round2ById[$provider->id] = $refined;
                } catch (\Throwable $e) {
                    $round2Failures[] = "{$provider->title}: refinement round failed ({$e->getMessage()}) — keeping its round-1 verdict.";
                }
            }
        }

        $perAgent = [];
        foreach ($successful1 as $r1) {
            $provider = $r1['provider'];
            $refined = $round2ById[$provider->id] ?? [];
            $verdicts = [];
            foreach ($requirements as $i => $_) {
                $verdicts[$i] = $refined[$i] ?? $r1['verdicts'][$i];
            }
            $perAgent[] = [
                'title'           => $provider->title,
                'round1Verdicts'  => $r1['verdicts'],
                'verdicts'        => $verdicts,
            ];
        }

        $results = [];
        foreach ($requirements as $i => $requirement) {
            $verdictsAtI = array_map(fn($a) => $a['verdicts'][$i], $perAgent);
            $results[] = [
                'requirement' => $requirement,
                'status'      => $this->reconcile($verdictsAtI),
                'evidence'    => null,
            ];
        }

        return [
            'results'        => $results,
            'perAgent'       => $perAgent,
            'disagreedCount' => count($disagreedIndexes),
            'round2Failures' => $round2Failures,
        ];
    }

    /** Majority vote across agents' final verdicts for one requirement; ties go to the stricter verdict. */
    public function reconcile(array $verdicts): string
    {
        $counts = array_count_values($verdicts);
        $maxCount = max($counts);
        $topStatuses = array_keys(array_filter($counts, fn($c) => $c === $maxCount));
        if (count($topStatuses) === 1) {
            return $topStatuses[0];
        }

        usort($topStatuses, fn($a, $b) => self::STRICTNESS[$a] <=> self::STRICTNESS[$b]);
        return $topStatuses[0];
    }

    /** Ports ComplianceController::parseAnalysis's / consensus-checker.mjs's line-matching convention. */
    public function parseVerdicts(array $requirements, ?string $rawAnalysis): array
    {
        $lines = explode("\n", $rawAnalysis ?? '');
        return array_map(function ($req) use ($lines) {
            $needle = mb_strtolower(mb_substr($req, 0, 20));
            $status = 'NOT_FOUND';
            foreach ($lines as $line) {
                if (! str_contains(mb_strtolower($line), $needle)) continue;
                $upper = mb_strtoupper($line);
                if (str_contains($upper, 'PARTIAL')) $status = 'PARTIAL';
                elseif (str_contains($upper, 'FOUND') && ! str_contains($upper, 'NOT_FOUND')) $status = 'FOUND';
                break;
            }
            return $status;
        }, $requirements);
    }

    private function buildRound1Prompt(string $diff, array $requirements): string
    {
        $reqLines = implode("\n", array_map(fn($r) => "- {$r}", $requirements));
        $diffText = $diff !== '' ? $diff : '(no diff available)';

        return "You are a compliance checker. Given this code diff, evaluate whether each requirement listed is addressed.\n\n"
            . "Diff:\n{$diffText}\n\n"
            . "Requirements to check:\n{$reqLines}"
            . "\n\nFor each requirement, respond with: FOUND, PARTIAL, or NOT_FOUND. One per line, format: '<requirement> | <status>'.";
    }

    private function buildRound2Prompt(string $diff, array $disagreedItems, int $selfProviderId, array $successful1): string
    {
        $peers = array_values(array_filter($successful1, fn($r) => $r['provider']->id !== $selfProviderId));
        $diffText = $diff !== '' ? $diff : '(no diff available)';

        $lines = [
            'You previously reviewed a code diff against a set of requirements. Other independent',
            'reviewers disagreed with you on some items below. Reconsider only these, in light of',
            "their assessments, and respond again in the same format.",
            '',
            "Diff:\n{$diffText}",
            '',
        ];

        foreach ($disagreedItems as $item) {
            $lines[] = "Requirement: {$item['requirement']}";
            foreach ($peers as $i => $peer) {
                $n = $i + 1;
                $lines[] = "  Reviewer {$n} said: {$peer['verdicts'][$item['index']]}";
            }
            $lines[] = '';
        }
        $lines[] = "For each requirement above, respond with: FOUND, PARTIAL, or NOT_FOUND. One per line, format: '<requirement> | <status>'.";

        return implode("\n", $lines);
    }
}
