<?php
namespace Tests\Unit;

use App\Jobs\SendDigestEmail;
use App\Mail\TriageDigest;
use App\Models\DigestSchedule;
use App\Services\DigestMailService;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SendDigestEmailTest extends TestCase
{
    use RefreshDatabase;

    private array $digestData = [
        'profile'   => 'production',
        'staleDays' => 5,
        'summary'   => ['total' => 2, 'needsResponse' => 1, 'aging' => 1],
        'tickets'   => [
            ['ticketKey' => 'PROJ-1', 'summary' => 'Fix cart', 'status' => 'Code Review', 'urgency' => 'needs-response', 'lastComment' => ['author' => 'Sarah', 'created' => '2026-03-05T10:00:00Z', 'body' => 'edge case'], 'daysSinceUpdate' => null, 'url' => 'https://jira.example.com/browse/PROJ-1'],
            ['ticketKey' => 'PROJ-2', 'summary' => 'Update docs', 'status' => 'In Progress', 'urgency' => 'aging', 'lastComment' => null, 'daysSinceUpdate' => 8, 'url' => 'https://jira.example.com/browse/PROJ-2'],
        ],
    ];

    public function test_job_sends_email_to_schedule_email_address(): void
    {
        Mail::fake();

        $schedule = DigestSchedule::create([
            'license_key_hash' => DigestSchedule::hashKey('test-key'),
            'email'      => 'dev@example.com',
            'timezone'   => 'UTC',
            'deliver_at' => '07:00:00',
        ]);

        $job = new SendDigestEmail($schedule->id, $this->digestData);
        $job->handle(app(DigestMailService::class));

        Mail::assertSent(TriageDigest::class, function ($mail) {
            return $mail->hasTo('dev@example.com');
        });
    }

    public function test_subject_includes_ticket_count_and_date(): void
    {
        Mail::fake();

        $schedule = DigestSchedule::create([
            'license_key_hash' => DigestSchedule::hashKey('key2'),
            'email'      => 'dev@example.com',
            'timezone'   => 'UTC',
            'deliver_at' => '07:00:00',
        ]);

        $job = new SendDigestEmail($schedule->id, $this->digestData);
        $job->handle(app(DigestMailService::class));

        Mail::assertSent(TriageDigest::class, function ($mailable) {
            $envelope = $mailable->envelope();
            return str_contains($envelope->subject, '2 ticket');
        });
    }
}
