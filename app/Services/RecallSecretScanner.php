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
    private const EDGE_PUNCTUATION_RE = '/^[`\'"(),.]+|[`\'"(),.]+$/';
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

        $despacedCombined = preg_replace('/\s+/', '', $combined);
        foreach (self::HARD_REJECT_PATTERNS as ['name' => $name, 're' => $re]) {
            if (preg_match($re, $combined) || preg_match($re, $despacedCombined)) {
                $article  = preg_match('/^[aeiou]/i', $name) ? 'n' : '';
                $reasons[] = "Looks like a{$article} {$name}.";
            }
        }

        // external_id is a system-generated filename (CLI note ID), never
        // user-authored free text. It's still checked on its own below, but
        // excluded from joinedChunkRuns: joining it with the trailing word of
        // another field produces a synthetic string whose entropy is an
        // artifact of concatenation, not a real secret (e.g. a body ending in
        // "5xx." glued to "...-7b556e.md").
        $freeText   = implode("\n", [$title, ...$aliases, ...$tags, $body, ...$sources]);
        $tokens     = array_values(array_filter(preg_split('/\s+/', $freeText)));
        $candidates = [...$tokens, ...$this->joinedChunkRuns($tokens), $externalId];

        foreach ($candidates as $token) {
            if ($token !== '' && ! preg_match(self::EMAIL_RE, $token) && $this->looksRandom($token, $combined)) {
                $reasons[] = 'Contains a long, random-looking string that could be a secret.';
                break;
            }
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

    private function isLabelWord(string $token): bool
    {
        $stripped = $this->stripEdgePunctuation($token);
        if (preg_match(self::GIT_REFERENCE_WORD_RE, $stripped)) {
            return true;
        }
        return preg_match('/^[A-Za-z]+$/', $stripped) === 1 && ! $this->hasInternalCaseSwitch($stripped);
    }

    /**
     * @param string[] $tokens
     * @return string[]
     */
    private function joinedChunkRuns(array $tokens): array
    {
        $runs  = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if ($this->isLabelWord($tokens[$i])) {
                continue;
            }
            $joined = $tokens[$i];
            for ($j = $i + 1; $j < min($count, $i + self::MAX_JOINED_CHUNKS); $j++) {
                if ($this->isLabelWord($tokens[$j])) {
                    break;
                }
                $joined .= $tokens[$j];
                $runs[]  = $joined;
            }
        }
        return $runs;
    }
}
