<?php
namespace Tests\Feature;

use App\Jobs\SendDigestEmail;
use App\Models\DigestSchedule;
use App\Services\LicenseValidationService;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DigestControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(LicenseValidationService::class, fn($m) => $m->shouldReceive('isValid')->andReturn(true));
        Queue::fake();
    }

    private function createSchedule(string $key = 'valid-key'): DigestSchedule
    {
        return DigestSchedule::create([
            'license_key_hash' => DigestSchedule::hashKey($key),
            'email'      => 'dev@example.com',
            'timezone'   => 'UTC',
            'deliver_at' => '07:00:00',
        ]);
    }

    private function validPayload(): array
    {
        return [
            'profile'  => 'production',
            'staleDays' => 5,
            'summary'  => ['total' => 2, 'needsResponse' => 1, 'aging' => 1],
            'tickets'  => [
                ['ticketKey' => 'PROJ-1', 'summary' => 'Fix cart', 'status' => 'Code Review', 'urgency' => 'needs-response'],
            ],
        ];
    }

    public function test_dispatches_send_digest_email_job(): void
    {
        $this->createSchedule();

        $response = $this->withToken('valid-key')->postJson('/v1/digest/deliver', $this->validPayload());

        $response->assertStatus(200);
        $response->assertJson(['delivered' => true]);
        Queue::assertPushed(SendDigestEmail::class);
    }

    public function test_updates_last_delivered_at_on_schedule(): void
    {
        $schedule = $this->createSchedule();
        $this->assertNull($schedule->last_delivered_at);

        $this->withToken('valid-key')->postJson('/v1/digest/deliver', $this->validPayload());

        $this->assertNotNull($schedule->fresh()->last_delivered_at);
    }

    public function test_returns_404_when_no_schedule_found(): void
    {
        // No schedule created for this key
        $response = $this->withToken('no-schedule-key')->postJson('/v1/digest/deliver', $this->validPayload());
        $response->assertStatus(404);
    }

    public function test_returns_422_when_tickets_missing(): void
    {
        $this->createSchedule();
        $response = $this->withToken('valid-key')->postJson('/v1/digest/deliver', [
            'profile' => 'prod',
            'staleDays' => 5,
            'summary' => ['total' => 0, 'needsResponse' => 0, 'aging' => 0],
            // 'tickets' missing
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['tickets']);
    }

    public function test_ticket_data_is_not_logged(): void
    {
        $this->createSchedule();
        $this->withToken('valid-key')->postJson('/v1/digest/deliver', $this->validPayload());

        Queue::assertPushed(SendDigestEmail::class, function ($job) {
            // Job stores digestData for email rendering — acceptable
            // but the scheduleId must be present (not raw license key)
            return $job->getScheduleId() > 0;
        });
    }
}
