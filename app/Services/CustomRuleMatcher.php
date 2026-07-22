<?php

namespace App\Services;

class CustomRuleMatcher
{
    /**
     * Test whether a ticket satisfies a rule's match conditions.
     * Ported 1:1 from ticket-lens/skills/jtb/scripts/lib/attention-scorer.mjs::matchesRuleConditions —
     * keep both in sync, parity enforced by tests/Fixtures/custom-rule-match-cases.json.
     */
    public function matches(array $ticket, array $match): bool
    {
        if (array_key_exists('priority', $match) && ($ticket['priority'] ?? null) !== $match['priority']) {
            return false;
        }

        if (array_key_exists('status', $match) && ($ticket['status'] ?? null) !== $match['status']) {
            return false;
        }

        if (array_key_exists('label', $match)) {
            $labels = is_array($ticket['labels'] ?? null) ? $ticket['labels'] : [];
            if (! in_array($match['label'], $labels, true)) {
                return false;
            }
        }

        if (array_key_exists('keyPrefix', $match) && ! str_starts_with((string) ($ticket['key'] ?? ''), (string) $match['keyPrefix'])) {
            return false;
        }

        return true;
    }

    /**
     * Every rule (no short-circuit) whose `action` matches and whose `match`
     * conditions are satisfied. Malformed rules (missing/non-array `match`,
     * unknown `action`) are skipped, never thrown — `config` has no schema
     * enforcement on read, only on write, and legacy rows may pre-date this.
     *
     * @return list<array>
     */
    public function matchingRules(array $ticket, array $rules, string $action): array
    {
        $matched = [];

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            if (($rule['action'] ?? null) !== $action) {
                continue;
            }
            if (! isset($rule['match']) || ! is_array($rule['match'])) {
                continue;
            }
            if ($this->matches($ticket, $rule['match'])) {
                $matched[] = $rule;
            }
        }

        return $matched;
    }
}
