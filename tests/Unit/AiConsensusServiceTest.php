<?php

namespace Tests\Unit;

use App\Exceptions\NotEnoughProvidersException;
use App\Models\AiProviderPool;
use App\Services\AiConsensusService;
use App\Services\AiProviderPoolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiConsensusServiceTest extends TestCase
{
    use RefreshDatabase;

    private const REQUIREMENTS = ['Must validate email', 'Must handle empty fields'];

    private function pool(int $id, string $title, string $endpoint = 'https://api.example.com/p'): AiProviderPool
    {
        return AiProviderPool::factory()->make([
            'id'       => $id,
            'title'    => $title,
            'endpoint' => $endpoint,
        ]);
    }

    private function service(): AiConsensusService
    {
        return new AiConsensusService(new AiProviderPoolService());
    }

    // ── reconcile() — pure logic, no HTTP ───────────────────────────────────

    public function test_reconcile_picks_the_majority_verdict(): void
    {
        $this->assertSame('FOUND', $this->service()->reconcile(['FOUND', 'FOUND', 'NOT_FOUND']));
    }

    public function test_reconcile_breaks_a_two_way_tie_toward_the_stricter_verdict(): void
    {
        $svc = $this->service();
        $this->assertSame('NOT_FOUND', $svc->reconcile(['FOUND', 'NOT_FOUND']));
        $this->assertSame('PARTIAL', $svc->reconcile(['FOUND', 'PARTIAL']));
        $this->assertSame('NOT_FOUND', $svc->reconcile(['PARTIAL', 'NOT_FOUND']));
    }

    public function test_reconcile_breaks_a_three_way_tie_toward_the_strictest_verdict(): void
    {
        $this->assertSame('NOT_FOUND', $this->service()->reconcile(['FOUND', 'PARTIAL', 'NOT_FOUND']));
    }

    // ── parseVerdicts() — pure logic, no HTTP ───────────────────────────────

    public function test_parse_verdicts_reads_found_partial_not_found(): void
    {
        $raw = "Must validate email | FOUND\nMust handle empty fields | NOT_FOUND";
        $this->assertSame(['FOUND', 'NOT_FOUND'], $this->service()->parseVerdicts(self::REQUIREMENTS, $raw));
    }

    public function test_parse_verdicts_does_not_read_not_found_as_found(): void
    {
        $this->assertSame(['NOT_FOUND'], $this->service()->parseVerdicts(['Must validate email'], 'Must validate email | NOT_FOUND'));
    }

    // ── run() — full round-1/round-2 orchestration over Http::fake() ───────

    public function test_makes_exactly_one_call_per_provider_and_skips_round_2_when_all_agents_agree(): void
    {
        Http::fake(fn() => Http::response(['choices' => [['message' => ['content' =>
            "Must validate email | FOUND\nMust handle empty fields | FOUND"
        ]]]], 200));

        $providers = [$this->pool(1, 'A'), $this->pool(2, 'B'), $this->pool(3, 'C')];
        $result = $this->service()->run($providers, '+diff', self::REQUIREMENTS);

        Http::assertSentCount(3); // one per provider, no round 2
        $this->assertSame(0, $result['disagreedCount']);
        $this->assertSame('FOUND', $result['results'][0]['status']);
    }

    public function test_disagreement_triggers_a_refinement_round_only_for_disagreed_requirements(): void
    {
        $seenRound2Prompts = [];
        Http::fake(function ($request) use (&$seenRound2Prompts) {
            $body = json_decode($request->body(), true);
            $content = $body['messages'][0]['content'];
            if (str_contains($content, 'Reviewer')) {
                $seenRound2Prompts[] = $content;
                return Http::response(['choices' => [['message' => ['content' => 'Must validate email | FOUND']]]], 200);
            }
            $isProviderB = str_contains($request->url(), 'p-b');
            $reply = $isProviderB
                ? "Must validate email | NOT_FOUND\nMust handle empty fields | FOUND"
                : "Must validate email | FOUND\nMust handle empty fields | FOUND";
            return Http::response(['choices' => [['message' => ['content' => $reply]]]], 200);
        });

        $providers = [
            $this->pool(1, 'A', 'https://api.example.com/p-a'),
            $this->pool(2, 'B', 'https://api.example.com/p-b'),
            $this->pool(3, 'C', 'https://api.example.com/p-c'),
        ];
        $result = $this->service()->run($providers, '+diff', self::REQUIREMENTS);

        $this->assertCount(3, $seenRound2Prompts, 'all 3 successful round-1 agents should get a refinement call');
        $this->assertStringContainsString('Must validate email', $seenRound2Prompts[0]);
        $this->assertStringNotContainsString('Must handle empty fields', $seenRound2Prompts[0], 'round 2 must only re-ask disagreed requirements');
        $this->assertSame('FOUND', $result['results'][0]['status']);
        $this->assertSame(1, $result['disagreedCount']);
    }

    public function test_still_succeeds_when_one_of_three_providers_errors_in_round_1(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'p-c')) {
                return Http::response([], 401);
            }
            return Http::response(['choices' => [['message' => ['content' =>
                "Must validate email | FOUND\nMust handle empty fields | FOUND"
            ]]]], 200);
        });

        $providers = [
            $this->pool(1, 'A', 'https://api.example.com/p-a'),
            $this->pool(2, 'B', 'https://api.example.com/p-b'),
            $this->pool(3, 'C', 'https://api.example.com/p-c'),
        ];
        $result = $this->service()->run($providers, '+diff', self::REQUIREMENTS);

        $this->assertCount(2, $result['perAgent']);
        $this->assertSame('FOUND', $result['results'][0]['status']);
    }

    public function test_throws_not_enough_providers_when_fewer_than_two_succeed(): void
    {
        Http::fake(fn() => Http::response([], 500));

        $providers = [$this->pool(1, 'A'), $this->pool(2, 'B')];

        $this->expectException(NotEnoughProvidersException::class);
        $this->expectExceptionMessage('Error: No AI provider is available or an unknown error has occurred.');
        $this->service()->run($providers, '+diff', self::REQUIREMENTS);
    }

    public function test_exactly_one_success_gets_a_consensus_specific_message_not_the_universal_one(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'p-a')) {
                return Http::response(['choices' => [['message' => ['content' => 'x | FOUND']]]], 200);
            }
            return Http::response([], 500);
        });

        $providers = [$this->pool(1, 'A', 'https://api.example.com/p-a'), $this->pool(2, 'B', 'https://api.example.com/p-b')];

        try {
            $this->service()->run($providers, '+diff', self::REQUIREMENTS);
            $this->fail('expected NotEnoughProvidersException');
        } catch (NotEnoughProvidersException $e) {
            $this->assertSame(1, $e->successCount);
            $this->assertStringContainsString('at least 2', $e->getMessage());
        }
    }

    public function test_falls_back_to_round_1_verdict_when_round_2_fails_for_one_agent(): void
    {
        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);
            $content = $body['messages'][0]['content'];
            $isRound2 = str_contains($content, 'Reviewer');
            $isProviderB = str_contains($request->url(), 'p-b');

            if ($isRound2 && $isProviderB) {
                return Http::response([], 500); // round-2 failure for B
            }
            if ($isRound2) {
                return Http::response(['choices' => [['message' => ['content' => 'Must validate email | FOUND']]]], 200);
            }
            $reply = $isProviderB
                ? "Must validate email | NOT_FOUND\nMust handle empty fields | FOUND"
                : "Must validate email | FOUND\nMust handle empty fields | FOUND";
            return Http::response(['choices' => [['message' => ['content' => $reply]]]], 200);
        });

        $providers = [
            $this->pool(1, 'A', 'https://api.example.com/p-a'),
            $this->pool(2, 'B', 'https://api.example.com/p-b'),
            $this->pool(3, 'C', 'https://api.example.com/p-c'),
        ];
        $result = $this->service()->run($providers, '+diff', self::REQUIREMENTS);

        $this->assertNotEmpty($result['round2Failures']);
        $this->assertStringContainsString('B', $result['round2Failures'][0]);
    }
}
