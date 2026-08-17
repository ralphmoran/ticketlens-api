<?php

namespace Tests\Unit\Services;

use App\Exceptions\RecallAttachmentException;
use App\Models\Group;
use App\Models\RecallNote;
use App\Models\User;
use App\Services\RecallAttachmentStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecallAttachmentStorageTest extends TestCase
{
    use RefreshDatabase;

    private RecallAttachmentStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new RecallAttachmentStorage();
        Storage::fake('local');
    }

    private function raw(string $filename, string $content): array
    {
        return ['filename' => $filename, 'content' => base64_encode($content)];
    }

    // ---- decode() ----

    public function test_decodes_a_valid_attachment(): void
    {
        $decoded = $this->storage->decode([$this->raw('notes.txt', 'hello world')]);

        $this->assertCount(1, $decoded);
        $this->assertSame('notes.txt', $decoded[0]['filename']);
        $this->assertSame('hello world', $decoded[0]['bytes']);
        $this->assertTrue($decoded[0]['isText']);
    }

    public function test_rejects_invalid_base64(): void
    {
        $this->expectException(RecallAttachmentException::class);
        $this->storage->decode([['filename' => 'a.txt', 'content' => '***not-base64***']]);
    }

    public function test_rejects_a_file_over_the_10mb_per_file_limit(): void
    {
        $previous = ini_set('memory_limit', '512M');
        $this->expectException(RecallAttachmentException::class);
        try {
            $this->storage->decode([$this->raw('big.bin', str_repeat('a', RecallAttachmentStorage::MAX_FILE_BYTES + 1))]);
        } finally {
            ini_set('memory_limit', $previous);
        }
    }

    public function test_rejects_when_aggregate_exceeds_the_12mb_total_limit(): void
    {
        // Deliberately smaller than the CLI's 50MB local-vault cap — see
        // MAX_TOTAL_BYTES's own comment: nginx's 20MB client_max_body_size
        // can't actually carry a 50MB-raw (~66MB base64) request body.
        $previous = ini_set('memory_limit', '256M');
        try {
            $chunk = str_repeat('a', 9 * 1024 * 1024); // 9MB each, under per-file cap
            $attachments = array_fill(0, 2, $this->raw('chunk.bin', $chunk)); // 18MB total, over the 12MB aggregate cap

            $this->expectException(RecallAttachmentException::class);
            $this->storage->decode($attachments);
        } finally {
            ini_set('memory_limit', $previous);
        }
    }

    public function test_accepts_an_aggregate_right_up_to_the_12mb_total_limit(): void
    {
        // Two files, each under the 10MB per-file cap, summing to exactly
        // MAX_TOTAL_BYTES — a single file at that size would trip the
        // per-file check first, so this needs two to isolate the total check.
        $previous = ini_set('memory_limit', '256M');
        try {
            $half = intdiv(RecallAttachmentStorage::MAX_TOTAL_BYTES, 2);
            $decoded = $this->storage->decode([
                $this->raw('a.bin', str_repeat('a', $half)),
                $this->raw('b.bin', str_repeat('b', $half)),
            ]);
            $this->assertCount(2, $decoded);
        } finally {
            ini_set('memory_limit', $previous);
        }
    }

    public function test_rejects_more_than_20_files(): void
    {
        $attachments = array_map(fn ($i) => $this->raw("f{$i}.txt", 'x'), range(1, 21));

        $this->expectException(RecallAttachmentException::class);
        $this->storage->decode($attachments);
    }

    public function test_sanitizes_a_path_traversal_filename(): void
    {
        $decoded = $this->storage->decode([$this->raw('../../etc/passwd', 'x')]);
        $this->assertSame('passwd', $decoded[0]['filename']);
    }

    public function test_sanitizes_unsafe_characters_in_filename(): void
    {
        $decoded = $this->storage->decode([$this->raw('report (final)!.txt', 'x')]);
        $this->assertSame('report__final__.txt', $decoded[0]['filename']);
    }

    public function test_a_png_is_not_treated_as_text(): void
    {
        // PNG magic bytes — not valid UTF-8, must never be scanned as text.
        $decoded = $this->storage->decode([$this->raw('shot.png', "\x89PNG\r\n\x1a\n")]);
        $this->assertFalse($decoded[0]['isText']);
    }

    public function test_a_txt_file_with_invalid_utf8_bytes_is_not_treated_as_text(): void
    {
        $decoded = $this->storage->decode([$this->raw('fake.txt', "\xFF\xFE\x00\x01")]);
        $this->assertFalse($decoded[0]['isText']);
    }

    public function test_SECURITY_a_secret_disguised_with_a_png_extension_is_still_treated_as_text(): void
    {
        // Confirmed-exploitable bypass, found in a live red-team pass
        // 2026-08-17: the old extension allowlist meant renaming a plain-text
        // secret to .png skipped the scan entirely. Classification is now
        // content-based (valid UTF-8), not extension-based — the filename
        // lie no longer matters.
        $decoded = $this->storage->decode([$this->raw('totally-a-real.png', 'AKIAIOSFODNN7EXAMPLE plain UTF-8 text, not actually a PNG')]);
        $this->assertTrue($decoded[0]['isText']);
    }

    public function test_a_real_jpeg_stays_classified_as_binary(): void
    {
        // JFIF magic bytes (0xFF 0xD8 0xFF) — not valid UTF-8 regardless of
        // the content-based reclassification above; genuine binary formats
        // must not start getting scanned as a side effect of the fix.
        $decoded = $this->storage->decode([$this->raw('real.jpg', "\xFF\xD8\xFF\xE0\x00\x10JFIF")]);
        $this->assertFalse($decoded[0]['isText']);
    }

    // ---- textForScan() ----

    public function test_text_for_scan_includes_only_text_like_attachments(): void
    {
        $decoded = $this->storage->decode([
            $this->raw('notes.txt', 'a secret maybe'),
            $this->raw('shot.png', "\x89PNG\r\n\x1a\n"),
        ]);

        $this->assertSame(['a secret maybe'], $this->storage->textForScan($decoded));
    }

    // ---- store() / deleteAll() ----

    public function test_store_persists_files_to_disk_and_creates_attachment_rows(): void
    {
        $owner = User::factory()->create(['is_owner' => true]);
        $group = Group::create(['name' => 'T', 'owner_id' => $owner->id]);
        $note = RecallNote::create([
            'group_id' => $group->id, 'external_id' => '1-abcdef.md', 'title' => 't',
            'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'b',
        ]);

        $decoded = $this->storage->decode([$this->raw('notes.txt', 'hello')]);
        $this->storage->store($note, $decoded);

        $this->assertCount(1, $note->fresh()->attachments);
        $attachment = $note->fresh()->attachments->first();
        Storage::disk('local')->assertExists($attachment->disk_path);
        $this->assertSame('hello', Storage::disk('local')->get($attachment->disk_path));
    }

    public function test_store_replaces_prior_attachments_on_a_re_push(): void
    {
        $owner = User::factory()->create(['is_owner' => true]);
        $group = Group::create(['name' => 'T', 'owner_id' => $owner->id]);
        $note = RecallNote::create([
            'group_id' => $group->id, 'external_id' => '1-abcdef.md', 'title' => 't',
            'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'b',
        ]);

        $this->storage->store($note, $this->storage->decode([$this->raw('first.txt', 'v1')]));
        $firstPath = $note->fresh()->attachments->first()->disk_path;

        $this->storage->store($note, $this->storage->decode([$this->raw('second.txt', 'v2')]));

        $attachments = $note->fresh()->attachments;
        $this->assertCount(1, $attachments);
        $this->assertSame('second.txt', $attachments->first()->filename);
        Storage::disk('local')->assertMissing($firstPath);
    }
}
