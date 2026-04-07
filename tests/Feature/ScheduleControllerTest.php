<?php
namespace Tests\Feature;

use App\Models\DigestSchedule;
use App\Services\LicenseValidationService;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ScheduleControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(LicenseValidationService::class, fn($m) => $m->shouldReceive('isValid')->andReturn(true));
    }

    public function test_stores_schedule_and_returns_201(): void
    {
        $response = $this->withToken('valid-key')->postJson('/v1/schedule', [
            'email' => 'dev@example.com',
            'timezone' => 'America/New_York',
            'deliverAt' => '07:00',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['scheduled', 'nextDelivery']);
        $response->assertJson(['scheduled' => true]);

        $this->assertDatabaseHas('digest_schedules', [
            'email' => 'dev@example.com',
            'timezone' => 'America/New_York',
            'deliver_at' => '07:00',
            'license_key_hash' => DigestSchedule::hashKey('valid-key'),
        ]);
    }

    public function test_updates_existing_schedule_on_duplicate_key(): void
    {
        $this->withToken('valid-key')->postJson('/v1/schedule', [
            'email' => 'old@example.com',
            'timezone' => 'UTC',
            'deliverAt' => '06:00',
        ]);

        $this->withToken('valid-key')->postJson('/v1/schedule', [
            'email' => 'new@example.com',
            'timezone' => 'America/New_York',
            'deliverAt' => '07:00',
        ]);

        $this->assertDatabaseCount('digest_schedules', 1);
        $this->assertDatabaseHas('digest_schedules', ['email' => 'new@example.com']);
    }

    public function test_show_returns_schedule_for_token(): void
    {
        DigestSchedule::create([
            'license_key_hash' => DigestSchedule::hashKey('valid-key'),
            'email' => 'dev@example.com',
            'timezone' => 'UTC',
            'deliver_at' => '07:00:00',
        ]);

        $response = $this->withToken('valid-key')->getJson('/v1/schedule');
        $response->assertStatus(200);
        $response->assertJsonStructure(['email', 'timezone', 'deliverAt', 'active', 'nextDelivery']);
    }

    public function test_show_returns_404_when_no_schedule(): void
    {
        $response = $this->withToken('valid-key')->getJson('/v1/schedule');
        $response->assertStatus(404);
    }

    public function test_destroy_removes_schedule(): void
    {
        DigestSchedule::create([
            'license_key_hash' => DigestSchedule::hashKey('valid-key'),
            'email' => 'dev@example.com',
            'timezone' => 'UTC',
            'deliver_at' => '07:00:00',
        ]);

        $response = $this->withToken('valid-key')->deleteJson('/v1/schedule');
        $response->assertStatus(200);
        $response->assertJson(['deleted' => true]);
        $this->assertDatabaseCount('digest_schedules', 0);
    }

    public function test_returns_422_for_invalid_timezone(): void
    {
        $response = $this->withToken('valid-key')->postJson('/v1/schedule', [
            'email' => 'dev@example.com',
            'timezone' => 'Not/ATimezone',
            'deliverAt' => '07:00',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['timezone']);
    }

    public function test_returns_422_for_invalid_time_format(): void
    {
        $response = $this->withToken('valid-key')->postJson('/v1/schedule', [
            'email' => 'dev@example.com',
            'timezone' => 'UTC',
            'deliverAt' => '25:00',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['deliverAt']);
    }

    public function test_raw_license_key_is_never_stored(): void
    {
        $this->withToken('my-secret-license-key')->postJson('/v1/schedule', [
            'email' => 'dev@example.com',
            'timezone' => 'UTC',
            'deliverAt' => '07:00',
        ]);

        $this->assertDatabaseMissing('digest_schedules', [
            'license_key_hash' => 'my-secret-license-key',
        ]);
    }
}
