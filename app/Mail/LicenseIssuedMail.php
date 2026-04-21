<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Delivers a newly-issued license key to the recipient.
 *
 * The raw key is in-memory on this mailable — NEVER log it, NEVER persist
 * this instance to a job queue table in cleartext form. Laravel's queue
 * serialization is fine because the serialized job is opaque and consumed
 * once. Do not dump/var_dump this instance in tests.
 */
class LicenseIssuedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $rawKey,
        public readonly string $tier,
        public readonly int $seats,
        public readonly ?Carbon $expiresAt = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your TicketLens license key',
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.license-issued',
            with: [
                'rawKey'    => $this->rawKey,
                'tier'      => $this->tier,
                'seats'     => $this->seats,
                'expiresAt' => $this->expiresAt?->toDateString(),
            ],
        );
    }
}
