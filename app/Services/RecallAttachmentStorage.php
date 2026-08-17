<?php

namespace App\Services;

use App\Exceptions\RecallAttachmentException;
use App\Models\RecallNote;
use App\Models\RecallNoteAttachment;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Decodes, validates, and stores Recall note attachments — mirrors the CLI's
 * own local-vault caps (attachment-uploader.mjs) so a push can never exceed
 * what the CLI already allowed a user to select. Private disk only ('local'):
 * these are team-scoped files, never publicly URL-addressable like avatars.
 */
class RecallAttachmentStorage
{
    public const MAX_FILE_BYTES = 10 * 1024 * 1024; // 10 MB — same as CLI's attachment-uploader.mjs
    // Deliberately smaller than the CLI's local-vault 50MB/call cap: nginx's
    // client_max_body_size is 20M (docker/nginx-proxy.conf), and base64
    // inflates raw bytes by ~4/3 before they ever reach this check — 50MB raw
    // would need a ~66MB request body, well past the proxy's ceiling, so that
    // cap could never actually be reached; requests over it 413 at nginx with
    // an opaque error instead of this class's clean 422. 12MB raw encodes to
    // ~16MB, leaving headroom under 20M for the rest of the JSON payload
    // (title/body/tags, up to ~50KB) and per-attachment key overhead. A file
    // can still save locally up to the CLI's full 50MB/call cap — only
    // syncing it to Console is capped here.
    public const MAX_TOTAL_BYTES = 12 * 1024 * 1024;
    public const MAX_FILES = 20;

    /**
     * @param array<int, array{filename: string, content: string}> $rawAttachments content is base64
     * @return array<int, array{filename: string, bytes: string, mime: string, isText: bool}>
     * @throws RecallAttachmentException
     */
    public function decode(array $rawAttachments): array
    {
        if (count($rawAttachments) > self::MAX_FILES) {
            throw new RecallAttachmentException('Too many attachments (max ' . self::MAX_FILES . ').');
        }

        $decoded    = [];
        $totalBytes = 0;

        foreach ($rawAttachments as $raw) {
            $bytes = base64_decode($raw['content'], true);
            if ($bytes === false) {
                throw new RecallAttachmentException("Attachment \"{$raw['filename']}\" is not valid base64.");
            }

            $size = strlen($bytes);
            if ($size > self::MAX_FILE_BYTES) {
                throw new RecallAttachmentException("Attachment \"{$raw['filename']}\" exceeds the 10MB per-file limit.");
            }

            $totalBytes += $size;
            if ($totalBytes > self::MAX_TOTAL_BYTES) {
                throw new RecallAttachmentException('Attachments exceed the 50MB total limit for this note.');
            }

            $filename = $this->sanitizeFilename($raw['filename']);

            $decoded[] = [
                'filename' => $filename,
                'bytes'    => $bytes,
                'mime'     => $this->detectMime($bytes),
                'isText'   => $this->looksLikeText($bytes),
            ];
        }

        return $decoded;
    }

    /**
     * @param array<int, array{filename: string, bytes: string, mime: string, isText: bool}> $decoded
     * @return string[] UTF-8 content of text-like attachments only, fed into RecallSecretScanner
     */
    public function textForScan(array $decoded): array
    {
        return array_values(array_map(
            fn (array $item) => $item['bytes'],
            array_filter($decoded, fn (array $item) => $item['isText']),
        ));
    }

    /**
     * Replaces a note's attachments wholesale — a re-push (upsert by
     * external_id) fully replaces content the same way the note body itself
     * does, so a stale attachment from a prior push can never linger.
     *
     * @param array<int, array{filename: string, bytes: string, mime: string, isText: bool}> $decoded
     */
    public function store(RecallNote $note, array $decoded): void
    {
        $this->deleteAll($note);

        foreach ($decoded as $item) {
            $path = "recall-attachments/{$note->group_id}/{$note->id}/" . Str::uuid() . "-{$item['filename']}";

            // Storage::put() returns false on failure rather than throwing —
            // an unchecked call here would silently leave a DB row pointing
            // at a file that was never written (same reasoning as
            // AvatarService::store()).
            if (Storage::disk('local')->put($path, $item['bytes']) === false) {
                throw new RecallAttachmentException("Unable to save attachment \"{$item['filename']}\".");
            }

            RecallNoteAttachment::create([
                'recall_note_id' => $note->id,
                'filename'       => $item['filename'],
                'disk_path'      => $path,
                'size_bytes'     => strlen($item['bytes']),
                'mime_type'      => $item['mime'],
            ]);
        }
    }

    public function deleteAll(RecallNote $note): void
    {
        // Queries fresh via the relation builder rather than the cached
        // $note->attachments property — store() calls deleteAll() then
        // immediately creates new rows on the same $note instance, and a
        // second store() call (re-push) on that same instance would
        // otherwise iterate a stale, pre-creation cached collection instead
        // of what's actually in the database.
        foreach ($note->attachments()->get() as $attachment) {
            Storage::disk('local')->delete($attachment->disk_path);
            $attachment->delete();
        }
    }

    // Strips directory components and unsafe characters — parity with the
    // CLI's sanitizeFilename() (attachment-downloader.mjs): same allowed
    // charset, so a filename round-trips identically whether written by the
    // CLI's local vault or the backend's own storage.
    private function sanitizeFilename(string $filename): string
    {
        return preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($filename));
    }

    private function detectMime(string $bytes): string
    {
        return (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';
    }

    // Content-based, not extension-based — a security-reviewed fix (2026-08-17
    // red-team exercise) for a real, confirmed-exploitable bypass: gating this
    // on the filename's extension (e.g. only .txt/.log/.md/...) meant renaming
    // a secret-bearing text file to .png skipped the scan entirely, even
    // though its actual bytes were plain UTF-8 text. Real binary formats
    // (images, PDFs, executables) essentially never validate as UTF-8 across
    // more than a few bytes, so this check is both stricter (can't be
    // bypassed by a filename lie) and has near-zero false-positive risk on
    // genuine binaries.
    private function looksLikeText(string $bytes): bool
    {
        return mb_check_encoding($bytes, 'UTF-8');
    }
}
