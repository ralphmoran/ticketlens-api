<?php
namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class TriageDigest extends Mailable
{
    public function __construct(public readonly array $digestData) {}

    public function envelope(): Envelope
    {
        $count = $this->digestData['summary']['total'] ?? 0;
        $label = $count === 1 ? 'ticket needs' : 'tickets need';
        $date  = now()->format('D M j');
        return new Envelope(
            subject: "Your triage digest — {$count} {$label} attention ({$date})",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.digest');
    }
}
