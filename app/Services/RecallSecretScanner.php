<?php

namespace App\Services;

/**
 * Server-side port of the CLI's skills/jtb/scripts/lib/secret-scanner.mjs —
 * defense-in-depth for the push endpoint. The CLI's own scan runs at note
 * capture time, but it's bypassable (a hand-edited vault file, a stale CLI
 * build, or a direct API call with a stolen/valid token never goes through
 * it), so the server must independently reject the same shapes before
 * persisting anything. Blocks outright on a match — never saves a redacted
 * version. Keep this in sync with the CLI's algorithm; it is not imported
 * from it (different language), so a change to one must be mirrored in the
 * other.
 *
 * Scans title + aliases + tags + body + sources + external_id together —
 * every free-text field a push payload carries — so a secret pasted into
 * any one of them can't slip through a scan that only looked at the body.
 * (tickets is excluded: it's regex-locked server-side to ticket-key shape,
 * no realistic secret fits it.)
 */
class RecallSecretScanner
{
    // Upper bound covers SHA-256 (64 hex chars), not just git's SHA-1 (40).
    private const GIT_SHA_RE = '/^[0-9a-f]{7,64}$/i';
    private const TICKET_KEY_RE = '/^[A-Z][A-Z0-9]+-\d+$/';
    private const GIT_REFERENCE_WORD_RE = '/\b(commit|sha\d*|revision|rev|digest|checksum|md5(sum)?|hash|fingerprint)\b/i';
    private const HASH_LABEL_PREFIX_RE = '/^[a-z0-9]+:/i';
    private const EDGE_PUNCTUATION_RE = '/^[`\'"(),.:]+|[`\'"(),.:]+$/';
    private const MIN_RANDOM_TOKEN_LENGTH = 20;
    // See secret-scanner.mjs for the full rationale behind this threshold.
    private const ENTROPY_THRESHOLD = 3.75;
    private const REFERENCE_CONTEXT_WINDOW = 20;
    private const MAX_JOINED_CHUNKS = 4;

    private const HARD_REJECT_PATTERNS = [
        ['name' => 'AWS access key', 're' => '/AKIA[0-9A-Z]{16}/'],
        ['name' => 'private key block', 're' => '/-----BEGIN (RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----/'],
        ['name' => 'JSON Web Token (JWT)', 're' => '/eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}/'],
        ['name' => 'API key', 're' => '/\b(sk-|gsk_)[A-Za-z0-9]{20,}\b/'],
        ['name' => 'GitHub token', 're' => '/\bgh[pousr]_[A-Za-z0-9]{20,}\b/'],
    ];

    private const EMAIL_RE = '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/';

    // A letters-only token ending in a recognized source-file extension reads
    // as high-entropy the same way a real secret does — a class name doubling
    // as its filename is the common case, but a deliberately-renamed or
    // accidentally letters-only secret (no digits, so the hard-reject
    // patterns above don't apply either) would match this shape too and MUST
    // NOT be silently waved through: security review confirmed a
    // full-exemption version of this check was a deterministic bypass (append
    // ".php" to any 20+ char letters-only secret and it passed clean). So
    // this only ever downgrades a match to a warning, never removes it from
    // the reject reasons — see looksLikeCodeFilename() and the two
    // independent checks against $randomCandidates in scan() below, not a
    // shortcut inside looksRandom itself. A bare identifier with no extension
    // (a method name, not a filename) isn't covered by this at all — that
    // shape is indistinguishable from a base64 secret fragment either way.
    // Kept in sync with CODE_FILENAME_RE in the CLI's secret-scanner.mjs.
    private const CODE_FILENAME_RE = '/^[A-Za-z]+\.(php|m?js|tsx?|jsx|py|rb|java|go|rs|vue|s?css|md|json|ya?ml|sh)$/i';

    /**
     * @param array{title?: string, aliases?: string[], tags?: string[], body?: string, sources?: string[], external_id?: string} $fields
     * @return array{rejected: bool, reasons: string[], warnings: string[]}
     */
    public function scan(array $fields): array
    {
        $title      = $fields['title'] ?? '';
        $aliases    = $fields['aliases'] ?? [];
        $tags       = $fields['tags'] ?? [];
        $body       = $fields['body'] ?? '';
        $sources    = $fields['sources'] ?? [];
        $externalId = $fields['external_id'] ?? '';

        $combined = implode("\n", [$title, ...$aliases, ...$tags, $body, ...$sources, $externalId]);
        $reasons  = [];
        $warnings = [];

        // Each field is tokenized on its own, and joinedChunkRuns runs
        // separately per field, so a trailing tag word can never glue onto
        // the next tag or onto the body's first word (mirrors the CLI fix in
        // secret-scanner.mjs — tags echoing a phrase already repeated in the
        // body were false-positiving via exactly this cross-field join).
        // external_id is a system-generated filename (CLI note ID), never
        // user-authored free text, so it's excluded from these groups
        // entirely — see the entropy-candidates comment further below for
        // why.
        $fieldTokenGroups = array_map(
            fn (string $field) => array_values(array_filter(preg_split('/\s+/', $field))),
            [$title, ...$aliases, ...$tags, $body, ...$sources],
        );
        $tokens = $this->flattenGroups($fieldTokenGroups);

        // A known secret shape (AWS key, API key prefix, JWT, PEM block...) is
        // recognized by a specific literal prefix. Checked three ways, each
        // catching what the others miss:
        //   1. $combined         — the unsplit occurrence.
        //   2. $hardRejectRuns   — each individually rejoined run (bounded to
        //      MAX_JOINED_CHUNKS tokens), which keeps the leading \b anchor
        //      intact for the two boundary-anchored patterns (API key,
        //      GitHub token) — stripping whitespace from the whole note at
        //      once would instead glue an unrelated preceding word onto the
        //      secret's first character and kill that anchor. Deliberately
        //      uses the flat, cross-field $tokens (not $fieldTokenGroups) —
        //      unlike the entropy pass below, these patterns match an exact
        //      literal prefix, so cross-field joining can't turn them into a
        //      false positive. Ported from the CLI's secret-scanner.mjs; the
        //      PHP port was missing this until now, a live false negative
        //      found in code review.
        //   3. $despacedCombined — the whole note with all whitespace
        //      stripped, unbounded in length. A fallback for fragmentation
        //      wider than #2's bounded window can reach. Reintroduces the
        //      same anchor risk #2 was built to avoid, but only alongside
        //      #2, never instead of it.
        $despacedCombined = preg_replace('/\s+/', '', $combined);
        $hardRejectRuns   = $this->joinedChunkRuns($tokens, stopAtLabelWords: false);
        foreach (self::HARD_REJECT_PATTERNS as ['name' => $name, 're' => $re]) {
            $matchesRun = array_any($hardRejectRuns, fn (string $run) => preg_match($re, $run) === 1);
            if (preg_match($re, $combined) || $matchesRun || preg_match($re, $despacedCombined)) {
                $article  = preg_match('/^[aeiou]/i', $name) ? 'n' : '';
                $reasons[] = "Looks like a{$article} {$name}.";
            }
        }

        // external_id is a system-generated filename (CLI note ID), never
        // user-authored free text — excluded entirely from the entropy/
        // random-string heuristic below, not just from joinedChunkRuns. A
        // random ID is, by construction, random-looking: scanning it for
        // "does this look like a secret" is a category error that rejects
        // some fraction of every real note purely by chance (observed twice
        // in Local Live Test — once via cross-field joining, once on its
        // own: "1784306812255-0dbb0e.md" alone has entropy 3.795, over the
        // 3.75 threshold). It still participates in $combined above, so a
        // literal secret SIGNATURE (AKIA/JWT/sk-/gh_) landing in external_id
        // is still hard-rejected — only the entropy heuristic exempts it.
        $joinedRunGroups = array_map(fn (array $group) => $this->joinedChunkRuns($group), $fieldTokenGroups);
        $candidates      = [...$tokens, ...$this->flattenGroups($joinedRunGroups)];

        // A code-filename-shaped candidate (looksLikeCodeFilename) is split
        // out into its own warning instead of a reject reason: security
        // review found that fully exempting this shape from the entropy
        // check was a deterministic bypass. Downgrading to a warning — never
        // silently dropping the signal — matches how an email address is
        // already handled below.
        $randomCandidates = array_values(array_filter(
            $candidates,
            fn (string $token) => $token !== '' && ! preg_match(self::EMAIL_RE, $token) && $this->looksRandom($token, $combined),
        ));
        if (array_any($randomCandidates, fn (string $token) => ! $this->looksLikeCodeFilename($token))) {
            $reasons[] = 'Contains a long, random-looking string that could be a secret.';
        }
        if (array_any($randomCandidates, fn (string $token) => $this->looksLikeCodeFilename($token))) {
            $warnings[] = 'Contains a code-filename-shaped token that also reads as high-entropy — double-check it is not a credential.';
        }

        if (preg_match(self::EMAIL_RE, $combined)) {
            $warnings[] = 'Contains an email address.';
        }

        return ['rejected' => count($reasons) > 0, 'reasons' => $reasons, 'warnings' => $warnings];
    }

    private function shannonEntropy(string $token): float
    {
        $counts = [];
        foreach (mb_str_split($token) as $ch) {
            $counts[$ch] = ($counts[$ch] ?? 0) + 1;
        }
        $length  = mb_strlen($token);
        $entropy = 0.0;
        foreach ($counts as $count) {
            $p = $count / $length;
            $entropy -= $p * log($p, 2);
        }
        return $entropy;
    }

    private function stripEdgePunctuation(string $token): string
    {
        return preg_replace(self::EDGE_PUNCTUATION_RE, '', $token);
    }

    private function isLabeledGitReference(string $rawToken, string $fullText): bool
    {
        $idx = mb_strpos($fullText, $rawToken);
        if ($idx === false) {
            return false;
        }
        $before = mb_substr($fullText, max(0, $idx - self::REFERENCE_CONTEXT_WINDOW), min($idx, self::REFERENCE_CONTEXT_WINDOW));
        $after  = mb_substr($fullText, $idx + mb_strlen($rawToken), self::REFERENCE_CONTEXT_WINDOW);
        return preg_match(self::GIT_REFERENCE_WORD_RE, $before) === 1 || preg_match(self::GIT_REFERENCE_WORD_RE, $after) === 1;
    }

    private function looksRandom(string $rawToken, string $fullText): bool
    {
        $token = $this->stripEdgePunctuation($rawToken);
        if (mb_strlen($token) < self::MIN_RANDOM_TOKEN_LENGTH) {
            return false;
        }
        if (preg_match(self::TICKET_KEY_RE, $token)) {
            return false;
        }

        if (preg_match(self::HASH_LABEL_PREFIX_RE, $token, $prefixMatch)) {
            $selfLabeled = preg_match(self::GIT_REFERENCE_WORD_RE, $prefixMatch[0])
                && preg_match(self::GIT_SHA_RE, mb_substr($token, mb_strlen($prefixMatch[0])));
            if ($selfLabeled) {
                return false;
            }
        }

        if (preg_match(self::GIT_SHA_RE, $token) && $this->isLabeledGitReference($rawToken, $fullText)) {
            return false;
        }

        return $this->shannonEntropy($token) >= self::ENTROPY_THRESHOLD;
    }

    private function hasInternalCaseSwitch(string $token): bool
    {
        return preg_match('/[a-z][A-Z]/', $token) === 1;
    }

    private function looksLikeCodeFilename(string $rawToken): bool
    {
        $stripped = $this->stripEdgePunctuation($rawToken);
        if (preg_match(self::CODE_FILENAME_RE, $stripped, $match) !== 1) {
            return false;
        }
        // Requiring an internal case switch in the stem (the same signal used
        // to detect base64 content elsewhere in this file) means a genuinely
        // random single-case letter run plus a fake extension gets no special
        // treatment at all — only tokens that already look like a real
        // PascalCase/camelCase identifier reach the softer warning path.
        return $this->hasInternalCaseSwitch($match[0]);
    }

    /**
     * @param array<int, string[]> $groups
     * @return string[]
     */
    private function flattenGroups(array $groups): array
    {
        return array_merge(...$groups);
    }

    private function isLabelWord(string $token): bool
    {
        // Apostrophe allowance ported from the CLI's secret-scanner.mjs
        // (ticket-lens@0e6dd82) — without it, an ordinary possessive next to
        // an unrelated hyphenated compound (e.g. "relay's decision-lookup")
        // never stops the run: both fail to qualify as a label word, so they
        // concatenate into one artificial blob whose mixed punctuation trips
        // the entropy threshold. Hyphenated tokens deliberately do NOT get
        // the same allowance — see joinedChunkRuns.
        $stripped = $this->stripEdgePunctuation($token);
        if (preg_match(self::GIT_REFERENCE_WORD_RE, $stripped)) {
            return true;
        }
        return preg_match('/^[A-Za-z]+(?:\'[A-Za-z]+)*$/', $stripped) === 1 && ! $this->hasInternalCaseSwitch($stripped);
    }

    /**
     * @param string[] $tokens
     * @return string[]
     */
    private function joinedChunkRuns(array $tokens, bool $stopAtLabelWords = true): array
    {
        $runs  = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if ($stopAtLabelWords && $this->isLabelWord($tokens[$i])) {
                continue;
            }
            $joined = $tokens[$i];
            for ($j = $i + 1; $j < min($count, $i + self::MAX_JOINED_CHUNKS); $j++) {
                if ($stopAtLabelWords && $this->isLabelWord($tokens[$j])) {
                    break;
                }
                $joined .= $tokens[$j];
                $runs[]  = $joined;
            }
        }
        return $runs;
    }
}
