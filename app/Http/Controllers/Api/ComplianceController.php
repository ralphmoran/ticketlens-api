<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\ComplianceRequest;
use App\Services\AnthropicService;
use Illuminate\Http\JsonResponse;

class ComplianceController
{
    public function __construct(private readonly AnthropicService $anthropic) {}

    public function handle(ComplianceRequest $request): JsonResponse
    {
        $brief     = $request->validated('brief');
        $ticketKey = $request->validated('ticketKey') ?? 'UNKNOWN';

        $requirements = $this->extractRequirements($brief);

        if (empty($requirements)) {
            return response()->json([
                'requirements'    => [],
                'results'         => [],
                'coveragePercent' => 0,
                'message'         => 'No acceptance criteria found in the ticket brief.',
            ]);
        }

        $prompt = "You are a compliance checker. Given this Jira ticket brief, evaluate whether each acceptance criterion listed is addressed in the ticket's description or mentioned code changes.\n\n"
            . "Brief:\n{$brief}\n\n"
            . "Requirements to check:\n"
            . implode("\n", array_map(fn($r) => "- {$r}", $requirements))
            . "\n\nFor each requirement, respond with: FOUND, PARTIAL, or NOT_FOUND. One per line, format: '<requirement> | <status>'.";

        $rawAnalysis = $this->anthropic->summarize($prompt);

        $results  = $this->parseAnalysis($requirements, $rawAnalysis);
        $found    = count(array_filter($results, fn($r) => $r['status'] === 'FOUND'));
        $partial  = count(array_filter($results, fn($r) => $r['status'] === 'PARTIAL'));
        $coverage = empty($results) ? 0 : (int) round(($found + $partial * 0.5) / count($results) * 100);

        return response()->json([
            'requirements'    => $requirements,
            'results'         => $results,
            'coveragePercent' => $coverage,
        ]);
    }

    private function extractRequirements(string $text): array
    {
        $lines   = explode("\n", $text);
        $results = [];
        $inAc    = false;

        foreach ($lines as $line) {
            if (preg_match('/^\s*#+\s*acceptance criteria\s*$/i', $line)) {
                $inAc = true;
                continue;
            }
            if ($inAc && preg_match('/^\s*#+\s/', $line)) {
                $inAc = false;
            }
            if (preg_match('/^\s*(given|when|then)\s+(.+)/i', $line)) {
                $results[] = trim($line);
                continue;
            }
            if (preg_match('/^\s*[-*]\s+(.+(?:must|should|shall|ensure|verify).+)/i', $line, $m)) {
                $results[] = trim($m[1]);
                continue;
            }
            if ($inAc && preg_match('/^\s*[-*\d.]\s*(.+)/', $line, $m)) {
                $results[] = trim($m[1]);
            }
        }

        return array_values(array_unique(array_filter($results)));
    }

    private function parseAnalysis(array $requirements, string $rawAnalysis): array
    {
        $results = [];
        foreach ($requirements as $req) {
            $status = 'NOT_FOUND';
            foreach (explode("\n", $rawAnalysis) as $line) {
                if (str_contains(strtolower($line), strtolower(substr($req, 0, 20)))) {
                    if (str_contains(strtoupper($line), 'PARTIAL')) {
                        $status = 'PARTIAL';
                    } elseif (str_contains(strtoupper($line), 'FOUND') && !str_contains(strtoupper($line), 'NOT_FOUND')) {
                        $status = 'FOUND';
                    }
                    break;
                }
            }
            $results[] = ['requirement' => $req, 'status' => $status, 'evidence' => null];
        }
        return $results;
    }
}
