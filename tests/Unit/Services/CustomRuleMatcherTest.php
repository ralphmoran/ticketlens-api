<?php

namespace Tests\Unit\Services;

use App\Services\CustomRuleMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CustomRuleMatcherTest extends TestCase
{
    private CustomRuleMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new CustomRuleMatcher();
    }

    #[DataProvider('fixtureCasesProvider')]
    public function test_matches_against_fixture_parity_cases(array $ticket, array $match, bool $expected): void
    {
        $this->assertSame($expected, $this->matcher->matches($ticket, $match));
    }

    public static function fixtureCasesProvider(): array
    {
        $cases = json_decode(
            file_get_contents(__DIR__ . '/../../Fixtures/custom-rule-match-cases.json'),
            associative: true,
        );

        $provided = [];
        foreach ($cases as $case) {
            $provided[$case['description']] = [$case['ticket'], $case['match'], $case['expected']];
        }
        return $provided;
    }

    // ── matchingRules — action filtering + collect-all (no first-match-wins) ──

    public function test_matching_rules_collects_all_rules_for_the_given_action(): void
    {
        $ticket = ['key' => 'PROJ-1', 'priority' => 'Highest', 'status' => 'Open', 'labels' => ['critical']];
        $rules  = [
            ['match' => ['priority' => 'Highest'], 'action' => 'notify', 'reason' => 'r1'],
            ['match' => ['label' => 'critical'], 'action' => 'notify', 'reason' => 'r2'],
            ['match' => ['priority' => 'Highest'], 'action' => 'force-urgent', 'reason' => 'not notify'],
        ];

        $matched = $this->matcher->matchingRules($ticket, $rules, 'notify');

        $this->assertCount(2, $matched);
        $this->assertSame('r1', $matched[0]['reason']);
        $this->assertSame('r2', $matched[1]['reason']);
    }

    public function test_matching_rules_returns_empty_when_nothing_matches(): void
    {
        $ticket = ['key' => 'PROJ-1', 'priority' => 'Low', 'status' => 'Open', 'labels' => []];
        $rules  = [['match' => ['priority' => 'Highest'], 'action' => 'notify']];

        $this->assertSame([], $this->matcher->matchingRules($ticket, $rules, 'notify'));
    }

    // ── Malformed-input guards — must never throw ──────────────────────────────

    public function test_matching_rules_skips_rule_with_missing_match(): void
    {
        $ticket = ['key' => 'PROJ-1', 'priority' => 'Highest'];
        $rules  = [['action' => 'notify']];

        $this->assertSame([], $this->matcher->matchingRules($ticket, $rules, 'notify'));
    }

    public function test_matching_rules_skips_rule_with_non_array_match(): void
    {
        $ticket = ['key' => 'PROJ-1', 'priority' => 'Highest'];
        $rules  = [['match' => 'not-an-array', 'action' => 'notify']];

        $this->assertSame([], $this->matcher->matchingRules($ticket, $rules, 'notify'));
    }

    public function test_matching_rules_skips_rule_with_unknown_action(): void
    {
        $ticket = ['key' => 'PROJ-1', 'priority' => 'Highest'];
        $rules  = [['match' => ['priority' => 'Highest'], 'action' => 'explode']];

        $this->assertSame([], $this->matcher->matchingRules($ticket, $rules, 'notify'));
    }

    public function test_matching_rules_returns_empty_when_rules_is_not_a_list(): void
    {
        $ticket = ['key' => 'PROJ-1', 'priority' => 'Highest'];

        $this->assertSame([], $this->matcher->matchingRules($ticket, ['not' => 'a list'], 'notify'));
    }

    public function test_matching_rules_skips_one_malformed_rule_without_dropping_the_rest(): void
    {
        $ticket = ['key' => 'PROJ-1', 'priority' => 'Highest'];
        $rules  = [
            ['action' => 'notify'], // missing match — skipped
            ['match' => ['priority' => 'Highest'], 'action' => 'notify', 'reason' => 'survives'],
        ];

        $matched = $this->matcher->matchingRules($ticket, $rules, 'notify');

        $this->assertCount(1, $matched);
        $this->assertSame('survives', $matched[0]['reason']);
    }
}
