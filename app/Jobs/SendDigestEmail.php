<?php
namespace App\Jobs;

use App\Models\DigestSchedule;
use App\Services\DigestMailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendDigestEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 60, 300];

    public function __construct(
        private readonly int $scheduleId,
        private readonly array $digestData,
    ) {}

    public function getScheduleId(): int { return $this->scheduleId; }
    public function getDigestData(): array { return $this->digestData; }

    public function handle(DigestMailService $mailer): void
    {
        $schedule = DigestSchedule::findOrFail($this->scheduleId);
        $mailer->send($schedule->email, $this->digestData, $schedule->timezone);
    }
}
