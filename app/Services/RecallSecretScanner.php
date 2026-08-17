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
    // Real compound-word segments ("dual-store", "not-yet-configured") run short —
    // capping segment length keeps a long random letters-only run from masquerading
    // as one "segment" of a fake compound. See isHyphenatedWordCompound.
    private const MAX_COMPOUND_SEGMENT_LENGTH = 15;
    private const HYPHENATED_COMPOUND_RE = '/^[A-Za-z]+(-[A-Za-z]+)+$/';
    // A candidate containing an unstripped '(', ')', '[', or ']' reads as
    // code syntax (an array/list literal element, or a function-call
    // argument) rather than a secret fragment. Consulted ONLY from
    // looksLikeCodeSyntax(), which downgrades a matching high-entropy
    // candidate to a warning — deliberately NOT wired into isLabelWord().
    // Code review of the first version of this fix caught a live CRITICAL:
    // making a bracket-bearing token an isLabelWord hard stop ends a
    // joinedChunkRuns run there unconditionally, but a bracket is trivial
    // for an attacker to insert anywhere ("a(b"), so it fully and SILENTLY
    // stopped a genuine fragmented secret split around it from ever being
    // reassembled for the entropy check (confirmed live: a real 36-char
    // secret split into two 18-char halves around a bare "a(b" separator
    // went from rejected:true to a fully silent rejected:false/warnings:[]
    // once isLabelWord treated brackets as a stop). The downgrade-only
    // design here avoids that: a bracket-bearing token is ordinary, never a
    // label word, so the join still happens — any joined candidate spanning
    // real secret content still trips the entropy check, and since that
    // joined candidate necessarily still contains the bracket character too,
    // looksLikeCodeSyntax() downgrades it to a WARNING rather than silently
    // exempting it. Ported from CLI's secret-scanner.mjs (backlog #14
    // residual: exact live repro was "['compliance', '--help']").
    private const CODE_SYNTAX_RE = '/[()\[\]]/';
    // \x{FEFF} (ZERO WIDTH NO-BREAK SPACE / BOM) is added explicitly: PCRE's
    // \s under plain /u (no (*UCP)) uses a fixed table that excludes it, while
    // JS's \s spec (ECMA-262 WhiteSpace production) includes it — the one real
    // parity gap /u alone doesn't close. Security review found a live bypass
    // without this: an AWS-key-shaped string split by U+FEFF stayed as one
    // unsplit token, invisible to every check (contiguous match, hard-reject
    // rejoin, despace) except entropy, which only catches it by chance.
    // \x{200B} (ZERO WIDTH SPACE) is added for the same reason, but it's in
    // neither language's native \s: despite the name, U+200B doesn't carry
    // the Unicode White_Space property (General_Category=Cf, not Zs), so
    // JS's \s never covered it either — this was a real parity-preserving
    // gap in both scanners, not something /u alone or JS's Unicode-aware \s
    // could ever have closed (backlog 1e).
    // Shared as a fragment (not just inside WHITESPACE_RE) so the PEM entry in
    // HARD_REJECT_PATTERNS below stays in sync with it automatically — see
    // that entry's comment for why it needs the same class.
    private const WHITESPACE_CLASS = '[\s\x{FEFF}\x{200B}]';

    // /u (PCRE_UTF8) makes \s Unicode-aware (U+00A0, U+2028, U+3000, etc.),
    // matching the JS scanner's native Unicode-aware \s — see secret-scanner.mjs
    // lines 260/281. Without it, a hard-reject secret split by a Unicode space
    // silently passed this scanner while the CLI still caught it (backlog 1c).
    private const WHITESPACE_RE = '/' . self::WHITESPACE_CLASS . '+/u';

    private const HARD_REJECT_PATTERNS = [
        ['name' => 'AWS access key', 're' => '/AKIA[0-9A-Z]{16}/'],
        // \s* (via WHITESPACE_CLASS*, not a literal space) between segments:
        // this is the only entry with required internal spacing, and
        // hardRejectRuns (no-separator rejoin) and $despacedCombined
        // (whitespace stripped) both destroy a literal space the same way
        // they correctly neutralize whitespace-splitting on every other
        // pattern here — so a tab, extra spaces, a newline, or no separator
        // at all used to bypass this entry completely (backlog 1d).
        // WHITESPACE_CLASS* closes all of those in one change since it's
        // tested against $combined, $hardRejectRuns, and $despacedCombined
        // identically, and reuses the same Unicode-aware class as
        // WHITESPACE_RE so this can't drift out of sync with it the way the
        // /u flag itself once did (backlog 1c).
        [
            'name' => 'private key block',
            're' => '/-----BEGIN' . self::WHITESPACE_CLASS . '*(RSA' . self::WHITESPACE_CLASS . '*|EC' . self::WHITESPACE_CLASS . '*|OPENSSH' . self::WHITESPACE_CLASS . '*|DSA' . self::WHITESPACE_CLASS . '*)?PRIVATE' . self::WHITESPACE_CLASS . '*KEY-----/u',
        ],
        ['name' => 'JSON Web Token (JWT)', 're' => '/eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}/'],
        ['name' => 'API key', 're' => '/\b(sk-|gsk_)[A-Za-z0-9]{20,}\b/'],
        ['name' => 'GitHub token', 're' => '/\bgh[pousr]_[A-Za-z0-9]{20,}\b/'],
    ];

    private const EMAIL_RE = '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/';

    // Shared between CODE_FILENAME_RE and FILENAME_REFERENCE_RE below, mirrors
    // the CLI's secret-scanner.mjs — one definition so the two can't drift.
    private const CODE_EXTENSION_ALTERNATION = 'php|m?js|tsx?|jsx|py|rb|java|go|rs|vue|s?css|md|json|ya?ml|sh';

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
    // Stem allows hyphens (this project's own file-naming convention) as
    // capture group 1, so looksLikeCodeFilename can judge a kebab-case stem
    // via isHyphenatedWordCompound. Kept in sync with CODE_FILENAME_RE in
    // the CLI's secret-scanner.mjs (backlog #13).
    private const CODE_FILENAME_RE = '/^([A-Za-z][A-Za-z-]*)\.(' . self::CODE_EXTENSION_ALTERNATION . ')$/i';

    // A hyphenated-stem filename optionally followed by a directly-attached
    // possessive ("note-command.mjs's") stops a joinedChunkRuns run the same
    // way any other label word does (see isLabelWord below). Deliberately
    // separate from CODE_FILENAME_RE: this only ever stops a *run* in
    // isLabelWord, it never exempts a token from the standalone entropy
    // check, so it can't itself bypass detection the way a change to
    // CODE_FILENAME_RE could. Ported from FILENAME_REFERENCE_RE in the CLI's
    // secret-scanner.mjs (backlog #13, ticket-lens@3c4b1d9).
    private const FILENAME_REFERENCE_RE = '/^[A-Za-z][A-Za-z-]*\.(' . self::CODE_EXTENSION_ALTERNATION . ')(\'s)?$/i';

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
            fn (string $field) => array_values(array_filter(preg_split(self::WHITESPACE_RE, $field))),
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
        $despacedCombined = preg_replace(self::WHITESPACE_RE, '', $combined);
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
        if (array_any($randomCandidates, fn (string $token) => ! $this->looksLikeCodeFilename($token) && ! $this->looksLikeCodeSyntax($token))) {
            $reasons[] = 'Contains a long, random-looking string that could be a secret.';
        }
        if (array_any($randomCandidates, fn (string $token) => $this->looksLikeCodeFilename($token))) {
            $warnings[] = 'Contains a code-filename-shaped token that also reads as high-entropy — double-check it is not a credential.';
        }
        if (array_any($randomCandidates, fn (string $token) => $this->looksLikeCodeSyntax($token))) {
            $warnings[] = 'Contains a code-syntax-shaped token (brackets or parentheses) that also reads as high-entropy — double-check it is not a credential.';
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
        // Two independent "structured, not random" signals: an internal case
        // switch (PascalCase/camelCase, the same signal used to detect
        // base64 content elsewhere in this file) or a hyphenated-compound
        // stem (this project's own kebab-case .mjs naming convention). A
        // genuinely random single-case, non-hyphenated letter run plus a
        // fake extension satisfies neither and gets no special treatment.
        return $this->hasInternalCaseSwitch($match[0]) || $this->isHyphenatedWordCompound($match[1]);
    }

    private function looksLikeFilenameReference(string $strippedToken): bool
    {
        return preg_match(self::FILENAME_REFERENCE_RE, $strippedToken) === 1;
    }

    /**
     * True for a candidate — a raw token OR a joinedChunkRuns result — that
     * itself contains an unstripped '(', ')', '[', or ']': code syntax
     * rather than a secret fragment. Deliberately NOT wired into
     * isLabelWord()/joinedChunkRuns() — see CODE_SYNTAX_RE's own comment for
     * why that hard-wall approach reopened a silent reassembly bypass —
     * instead this only downgrades a candidate that ALREADY tripped
     * looksRandom, same never-exempt treatment as looksLikeCodeFilename.
     * Covers both backlog #14 residual shapes: a token already 20+ chars on
     * its own with no join needed (e.g. "matches(['compliance',"), and a
     * joined run that only crosses the entropy threshold once several
     * bracket-literal tokens glue together (e.g.
     * "['compliance','--help','-h','debug']") — the bracket character
     * survives into the joined string either way, so this still catches it.
     * Fully exempting this shape would let a 20+ char secret dodge rejection
     * just by wrapping it in a fake "f(" / "[".
     */
    private function looksLikeCodeSyntax(string $rawToken): bool
    {
        return preg_match(self::CODE_SYNTAX_RE, $this->stripEdgePunctuation($rawToken)) === 1;
    }

    /**
     * @param array<int, string[]> $groups
     * @return string[]
     */
    private function flattenGroups(array $groups): array
    {
        return array_merge(...$groups);
    }

    /**
     * True for a letters-only, hyphen-delimited token whose segments are all
     * short enough to read as a real compound word ("dual-store",
     * "REDIS-vs-PG", "not-yet-configured") rather than a whitespace-split
     * secret fragment. Only consulted from isLabelWord, i.e. only affects
     * the $stopAtLabelWords=true (generic-secret entropy) pass —
     * joinedChunkRuns's $stopAtLabelWords=false pass, which is what
     * HARD_REJECT_PATTERNS relies on to catch a whitespace-split
     * sk-/gsk_/AKIA/gh*_/eyJ/PEM-prefixed secret, never calls isLabelWord at
     * all, so this cannot weaken that protection (see the regression test
     * for exactly that shape, still passing after this change).
     *
     * The hasInternalCaseSwitch check matters the same way it does
     * everywhere else in this file that distinguishes base64 content from
     * prose: without it, a base64-shaped fragment like "zqXvbNmKl-PoIuYtR"
     * reads as a "compound word" purely because it happens to contain a
     * hyphen — the same category of shape-based exemption that made the
     * code-filename bypass CRITICAL.
     */
    private function isHyphenatedWordCompound(string $token): bool
    {
        if (preg_match(self::HYPHENATED_COMPOUND_RE, $token) !== 1) {
            return false;
        }
        if ($this->hasInternalCaseSwitch($token)) {
            return false;
        }
        foreach (explode('-', $token) as $segment) {
            if (strlen($segment) > self::MAX_COMPOUND_SEGMENT_LENGTH) {
                return false;
            }
        }
        return true;
    }

    /**
     * Ported from the CLI's secret-scanner.mjs (ticket-lens@0e6dd82) — keep
     * these rules in sync with the JS version there.
     *
     * The apostrophe allowance matters because without it, an ordinary
     * possessive next to an unrelated hyphenated compound (e.g. "relay's
     * decision-lookup") never stops the run: both fail to qualify as a
     * label word, so they
     * concatenate into one artificial blob whose mixed punctuation trips
     * the entropy threshold. Hyphenated compounds get the narrower
     * isHyphenatedWordCompound allowance rather than the full apostrophe
     * treatment: a short prefix + hyphen + one long unstructured letter-run
     * (e.g. "sk-abcdefghijklmnop") still fails it — one segment exceeds
     * MAX_COMPOUND_SEGMENT_LENGTH — so it stays eligible to join. This is
     * belt-and-suspenders on top of the fact noted above that the
     * hard-reject pass doesn't consult isLabelWord in the first place.
     */
    private function isLabelWord(string $token): bool
    {
        $stripped = $this->stripEdgePunctuation($token);
        if (preg_match(self::GIT_REFERENCE_WORD_RE, $stripped)) {
            return true;
        }
        if ($this->isHyphenatedWordCompound($stripped)) {
            return true;
        }
        if ($this->looksLikeFilenameReference($stripped)) {
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
