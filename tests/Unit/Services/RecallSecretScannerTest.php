<?php

namespace Tests\Unit\Services;

use App\Services\RecallSecretScanner;
use Tests\TestCase;

class RecallSecretScannerTest extends TestCase
{
    private RecallSecretScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scanner = new RecallSecretScanner();
    }

    // ---- clean input ----

    public function test_plain_text_is_not_rejected_and_has_no_warnings(): void
    {
        $result = $this->scanner->scan(['title' => 'Retry gotcha', 'body' => 'Needs exponential backoff, not a fixed delay.']);
        $this->assertFalse($result['rejected']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_a_jira_ticket_key_does_not_falsely_trigger_the_random_string_check(): void
    {
        $result = $this->scanner->scan(['body' => 'See PROD-123456 for background.']);
        $this->assertFalse($result['rejected']);
    }

    public function test_a_git_commit_sha_labeled_as_a_commit_is_allowed_through(): void
    {
        $result = $this->scanner->scan(['body' => 'Fixed in commit a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0']);
        $this->assertFalse($result['rejected']);
    }

    // ---- hard-reject patterns ----

    public function test_an_aws_access_key_is_rejected(): void
    {
        $result = $this->scanner->scan(['body' => 'Prod key is AKIAIOSFODNN7EXAMPLE']);
        $this->assertTrue($result['rejected']);
        $this->assertStringContainsString('AWS access key', $result['reasons'][0]);
    }

    public function test_a_jwt_is_rejected(): void
    {
        $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U';
        $result = $this->scanner->scan(['body' => "Token: {$jwt}"]);
        $this->assertTrue($result['rejected']);
    }

    public function test_a_pem_private_key_block_is_rejected(): void
    {
        $result = $this->scanner->scan(['body' => "-----BEGIN RSA PRIVATE KEY-----\nMIIEow...\n-----END RSA PRIVATE KEY-----"]);
        $this->assertTrue($result['rejected']);
    }

    public function test_an_openai_style_api_key_is_rejected(): void
    {
        $result = $this->scanner->scan(['body' => 'key=sk-abcdefghijklmnopqrstuvwxyz123456']);
        $this->assertTrue($result['rejected']);
    }

    public function test_a_github_token_is_rejected(): void
    {
        $result = $this->scanner->scan(['body' => 'ghp_' . str_repeat('a1B2c3', 4)]);
        $this->assertTrue($result['rejected']);
    }

    public function test_a_secret_split_by_a_single_space_is_still_rejected(): void
    {
        $result = $this->scanner->scan(['body' => 'AKIA IOSFODNN7EXAMPLE']);
        $this->assertTrue($result['rejected']);
    }

    // ---- drift fix: port the JS anchor-safe bounded rejoin (hardRejectRuns) ----
    //
    // The JS scanner (secret-scanner.mjs) checks HARD_REJECT_PATTERNS against
    // three things: the raw combined text, an unbounded whitespace-stripped
    // copy (despacedCombined), and a bounded per-token rejoin that preserves
    // the leading \b anchor (hardRejectRuns). The PHP port only ever had the
    // first two — despacing the WHOLE note at once glues the word directly
    // preceding a split secret onto its first character, which kills the
    // leading \b anchor the API-key and GitHub-token patterns depend on. Live
    // false negative found in code review, predates this diff's other fixes.

    public function test_l2_regression_a_low_entropy_api_key_shaped_secret_split_by_a_space_and_preceded_by_an_ordinary_word_is_still_rejected(): void
    {
        // Low-entropy on purpose (heavily repeated character) so this can
        // only be caught by the exact-shape hard-reject pattern, never by
        // the entropy layer. Before the fix: despacing the whole note glued
        // "the" directly onto "sk-", killing the pattern's leading \b so it
        // silently missed.
        $result = $this->scanner->scan(['body' => 'the sk- AAAAAAAAAAAAAAAAAAAAAAAA1 key']);
        $this->assertTrue($result['rejected']);
        $this->assertStringContainsString('API key', implode(' ', $result['reasons']));
    }

    public function test_l2_regression_same_bug_shape_for_the_github_token_pattern(): void
    {
        $result = $this->scanner->scan(['body' => 'token ghp_ AAAAAAAAAAAAAAAAAAAAAAAA1 here']);
        $this->assertTrue($result['rejected']);
        $this->assertStringContainsString('GitHub token', implode(' ', $result['reasons']));
    }

    // ---- entropy-based random string detection ----

    public function test_a_long_random_looking_string_is_rejected(): void
    {
        $result = $this->scanner->scan(['body' => 'Token: 9f8a7b6c5d4e3f2a1b0c9d8e7f6a5b4c3d2e1f0a']);
        $this->assertTrue($result['rejected']);
        $this->assertStringContainsString('random-looking string', $result['reasons'][0]);
    }

    public function test_a_bare_unlabeled_hex_shaped_secret_is_rejected(): void
    {
        $result = $this->scanner->scan(['body' => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0']);
        $this->assertTrue($result['rejected']);
    }

    // ---- scans every field, not just body ----

    public function test_a_secret_in_the_title_is_rejected(): void
    {
        $result = $this->scanner->scan(['title' => 'Prod creds: AKIAIOSFODNN7EXAMPLE', 'body' => 'x']);
        $this->assertTrue($result['rejected']);
    }

    public function test_a_secret_in_a_tag_is_rejected(): void
    {
        $result = $this->scanner->scan(['title' => 'x', 'tags' => ['AKIAIOSFODNN7EXAMPLE'], 'body' => 'x']);
        $this->assertTrue($result['rejected']);
    }

    public function test_a_secret_in_a_source_link_is_rejected(): void
    {
        $result = $this->scanner->scan(['title' => 'x', 'body' => 'x', 'sources' => ['https://example.com?key=AKIAIOSFODNN7EXAMPLE']]);
        $this->assertTrue($result['rejected']);
    }

    public function test_a_secret_in_external_id_is_rejected(): void
    {
        $result = $this->scanner->scan(['title' => 'x', 'body' => 'x', 'external_id' => 'AKIAIOSFODNN7EXAMPLE']);
        $this->assertTrue($result['rejected']);
    }

    public function test_a_secret_in_aliases_is_rejected(): void
    {
        // aliases is free-text (validated only for length, not shape), persisted
        // verbatim, and returned on every pull — it must be scanned like any
        // other user-authored field, not silently exempted.
        $result = $this->scanner->scan(['title' => 'x', 'body' => 'x', 'aliases' => ['AKIAIOSFODNN7EXAMPLE']]);
        $this->assertTrue($result['rejected']);
    }

    // ---- external_id is a system-generated filename, not user free text ----

    public function test_cli_generated_external_id_does_not_falsely_combine_with_body_text(): void
    {
        // Real Local Live Test failure: the CLI's own note filename
        // ("1784260956978-7b556e.md") sits next to the body's trailing "5xx."
        // in the flattened token stream. joinedChunkRuns used to glue them
        // into "5xx.1784260956978-7b556e.md" (entropy 3.81, over the 3.75
        // threshold) and reject a note that contains no secret at all.
        $result = $this->scanner->scan([
            'title'       => 'Retry gotcha (live test)',
            'tags'        => ['livetest'],
            'body'        => 'Retry needs exponential backoff on 5xx.',
            'external_id' => '1784260956978-7b556e.md',
        ]);
        $this->assertFalse($result['rejected']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_a_cli_generated_external_id_is_never_flagged_as_a_random_looking_string_on_its_own(): void
    {
        // Second Local Live Test failure: a DIFFERENT CLI-generated id
        // ("1784306812255-0dbb0e.md") with otherwise completely clean title/
        // tags/body was rejected on its own — no joining involved. Its
        // standalone Shannon entropy (3.795) crosses the 3.75 threshold
        // purely because a random ID is, by definition, random-looking. The
        // entropy heuristic exists to catch a user-authored secret pasted
        // into free text — a system-generated filename was never that, and
        // scanning it for "looks random" is a category error: some fraction
        // of every real ID will cross the threshold by chance.
        $result = $this->scanner->scan([
            'title'       => 'Impact verification note',
            'tags'        => ['impacttest'],
            'body'        => 'Impact-verification body text for tombstone round trip.',
            'external_id' => '1784306812255-0dbb0e.md',
        ]);
        $this->assertFalse($result['rejected']);
    }

    // ---- email: warning, not rejection ----

    public function test_an_email_address_triggers_a_warning_not_a_rejection(): void
    {
        $result = $this->scanner->scan(['body' => 'Ping ralph@example.com about this.']);
        $this->assertFalse($result['rejected']);
        $this->assertNotEmpty($result['warnings']);
    }

    // ---- regression: colon-suffixed label word must stop a joined-chunk run ----

    public function test_a_colon_labeled_sentence_opener_next_to_a_ticket_key_is_not_falsely_joined_into_a_rejection(): void
    {
        $result = $this->scanner->scan(['body' => 'Generalizes: PROD-123456 and any sibling ticket touching this path should check it first.']);
        $this->assertFalse($result['rejected']);
    }

    public function test_a_colon_labeled_sentence_opener_next_to_ordinary_prose_is_not_falsely_joined_into_a_rejection(): void
    {
        $result = $this->scanner->scan(['body' => 'Generalizes: any future ticket that touches this path should check the same thing first.']);
        $this->assertFalse($result['rejected']);
    }

    // ---- regression: fields are scanned independently for the entropy/random-string check ----

    public function test_two_tags_that_individually_echo_phrases_repeated_in_the_body_do_not_falsely_combine_into_a_rejection(): void
    {
        $result = $this->scanner->scan([
            'tags' => ['credit-application', 'add-finance-source'],
            'body' => 'Credit Application flow now calls Add Finance Source correctly after the fix.',
        ]);
        $this->assertFalse($result['rejected']);
    }

    public function test_a_secret_split_across_the_body_by_whitespace_is_still_caught_per_field_joining_still_works_within_one_field(): void
    {
        $result = $this->scanner->scan(['tags' => ['clean'], 'body' => 'AKIA IOSFODNN7EXAMPLE']);
        $this->assertTrue($result['rejected']);
    }

    // ---- regression: bare code-identifier filenames are not secrets ----

    public function test_a_pascalcase_class_name_as_filename_with_a_recognized_source_extension_is_not_rejected_but_does_warn(): void
    {
        $result = $this->scanner->scan(['body' => 'RouteOneAutoEcontracting.php handles the integration.']);
        $this->assertFalse($result['rejected']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('code-filename-shaped', implode(' ', $result['warnings']));
    }

    public function test_positive_control_digits_in_the_stem_are_not_exempted_still_a_hard_reject_not_even_a_warning(): void
    {
        $result = $this->scanner->scan(['body' => 'Base64EncoderForV2Payloads123456789.php handles this.']);
        $this->assertTrue($result['rejected']);
    }

    public function test_known_accepted_gap_a_bare_camelcase_method_name_with_no_file_extension_is_still_misread_as_random(): void
    {
        // Same shape as a base64 secret fragment — documents the known gap,
        // does not silently fix it. See looksRandom doc comment.
        $result = $this->scanner->scan(['body' => 'The fix calls generateDealJacketForTekionSubmission after validation succeeds every time.']);
        $this->assertTrue($result['rejected']);
    }

    public function test_security_regression_a_random_letters_only_secret_disguised_with_a_fake_extension_is_downgraded_to_a_warning_never_silently_exempted(): void
    {
        // Real secret shape (no digits, so HARD_REJECT_PATTERNS don't apply),
        // just wearing a ".php" suffix to probe the filename carve-out.
        // Before the fix this returned rejected:false with NO warning at
        // all — a silent bypass caught in security review.
        $result = $this->scanner->scan(['body' => 'XqZkTmWpLbNvRcYsHjFgAbCd.php was mentioned once.']);
        $this->assertFalse($result['rejected']);
        $this->assertStringContainsString('code-filename-shaped', implode(' ', $result['warnings']));
    }

    public function test_security_regression_a_random_token_with_no_internal_case_switch_plus_a_fake_extension_gets_a_full_reject_not_even_a_warning(): void
    {
        $result = $this->scanner->scan(['body' => 'xqzktmwplbnvrcyshjfgabcd.php was mentioned once.']);
        $this->assertTrue($result['rejected']);
    }

    // ---- documented accepted gap: entropy join no longer spans a field boundary ----

    public function test_a_secret_split_exactly_across_a_tag_and_the_body_is_not_caught_by_the_entropy_join(): void
    {
        // Trade-off accepted alongside the Trigger-3 field-boundary fix
        // above: splitting a generic (non-hard-reject-shaped) high-entropy
        // secret across two different fields no longer reassembles for the
        // entropy check, only within one field. Pinned down here so it
        // can't silently regress further without this test being touched.
        $result = $this->scanner->scan(['tags' => ['XqZkTmWpLbNv'], 'body' => 'RcYsHjFgAbCdEfGh']);
        $this->assertFalse($result['rejected']);
    }

    // ---- drift fix: port the JS apostrophe allowance (ticket-lens@0e6dd82) ----

    public function test_possessive_next_to_a_hyphenated_compound_is_not_a_secret(): void
    {
        // The JS scanner (secret-scanner.mjs) fixed this in 0e6dd82 — the
        // PHP port never got the matching isLabelWord apostrophe allowance
        // and has drifted since. This closes that gap.
        $result = $this->scanner->scan(['body' => "the relay's decision-lookup query never returned them"]);
        $this->assertFalse($result['rejected']);
    }

    public function test_a_whitespace_split_api_key_whose_letters_only_half_looks_hyphen_compound_shaped_is_still_rejected(): void
    {
        // The apostrophe fix must not extend to hyphens — this must stay caught.
        $result = $this->scanner->scan(['body' => "sk-abcdefghijklmnop\tqrstuvwxyz123456"]);
        $this->assertTrue($result['rejected']);
    }

    // ---- Trigger 5: two adjacent hyphenated compounds are not a secret ----

    public function test_exact_live_repro_redis_vs_pg_dual_store_false_positived_in_triage(): void
    {
        $result = $this->scanner->scan(['body' => 'the known REDIS-vs-PG dual-store gotcha strikes again']);
        $this->assertFalse($result['rejected']);
    }

    public function test_two_ordinary_lowercase_compounds_adjacent_no_abbreviation_involved(): void
    {
        $result = $this->scanner->scan(['body' => 'we hit a well-known edge-case yesterday']);
        $this->assertFalse($result['rejected']);
    }

    public function test_a_hyphenated_token_with_one_oversized_segment_does_not_qualify_as_a_compound_so_a_generic_secret_split_next_to_it_is_still_caught(): void
    {
        // Neither half is 20+ chars alone, and neither matches any
        // HARD_REJECT_PATTERNS prefix — this isolates the entropy join
        // specifically. "ab-cdefghijklmnopqr" has a 17-char second segment,
        // over MAX_COMPOUND_SEGMENT_LENGTH (15), so it stays joinable
        // instead of being misread as a compound word.
        $result = $this->scanner->scan(['body' => 'the ab-cdefghijklmnopqr Xy9zAb value leaked']);
        $this->assertTrue($result['rejected']);
    }

    public function test_security_regression_a_base64_shaped_hyphenated_fragment_does_not_qualify_as_a_compound_word_just_because_it_contains_a_hyphen(): void
    {
        // Found by adversarial security review: without a case-switch guard, a
        // mixed-case fragment like "zqXvbNmKl-PoIuYtR" would read as a
        // "compound word" purely by shape (letters + hyphen + short segments)
        // and stop the entropy join — the same category of shape-based
        // exemption that made the code-filename bypass CRITICAL. No real
        // compound word has an internal case switch, so this costs nothing
        // legitimate.
        $result = $this->scanner->scan(['body' => 'zqXvbNmKl-PoIuYtR eWqAsDfG-hJkLzXcV']);
        $this->assertTrue($result['rejected']);
    }
}
