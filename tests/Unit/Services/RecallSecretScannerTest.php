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
}
