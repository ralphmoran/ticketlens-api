<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Forwards a landing-page enterprise enquiry to the sales address.
 * Sent synchronously: the visitor's address is only ever a Reply-To.
 */
class EnterpriseContactMail extends Mailable
{
    public function __construct(
        public readonly string $senderName,
        public readonly string $senderEmail,
        public readonly string $body,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->senderEmail, $this->senderName)],
            subject: "Enterprise enquiry from {$this->senderName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.enterprise-contact',
            with: [
                'senderName'  => $this->senderName,
                'senderEmail' => $this->senderEmail,
                'body'        => $this->body,
            ],
        );
    }
}
