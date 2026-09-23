<?php

namespace Tests\Feature\Api;

use App\Models\ErrorReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErrorReportControllerTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'cli_version' => '0.39.5',
            'os'          => 'darwin',
            'command'     => 'note add',
            'message'     => 'ENOENT: no such file or directory',
            'stack_trace' => "at readNote (note-command.mjs:42)\nat main (ticketlens.mjs:10)",
            'profile_tier' => 'pro',
        ], $overrides);
    }

    public function test_a_valid_report_is_stored_and_returns_201(): void
    {
        $this->postJson('/v1/reports', $this->validPayload())
            ->assertStatus(201)
            ->assertJson(['received' => true]);

        $this->assertSame(1, ErrorReport::count());
        $report = ErrorReport::first();
        $this->assertSame('0.39.5', $report->cli_version);
        $this->assertSame('note add', $report->command);
        $this->assertSame('pro', $report->profile_tier);
    }

    public function test_no_cli_token_is_required_because_auth_itself_may_be_the_failure(): void
    {
        // Deliberately no ->withToken() — confirms the route sits outside
        // auth.cli (routes/api.php), the whole reason this endpoint exists.
        $this->postJson('/v1/reports', $this->validPayload())
            ->assertStatus(201);
    }

    public function test_message_is_required(): void
    {
        $payload = $this->validPayload();
        unset($payload['message']);

        $this->postJson('/v1/reports', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('message');
        $this->assertSame(0, ErrorReport::count());
    }

    public function test_cli_version_is_required(): void
    {
        $payload = $this->validPayload();
        unset($payload['cli_version']);

        $this->postJson('/v1/reports', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('cli_version');
    }

    public function test_an_invalid_profile_tier_is_rejected(): void
    {
        $this->postJson('/v1/reports', $this->validPayload(['profile_tier' => 'bogus']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('profile_tier');
    }

    public function test_os_and_stack_trace_and_command_are_optional(): void
    {
        $this->postJson('/v1/reports', [
            'cli_version' => '0.39.5',
            'message'     => 'Something broke',
        ])->assertStatus(201);

        $this->assertSame(1, ErrorReport::count());
        $this->assertNull(ErrorReport::first()->os);
    }

    // ---- secret scanning — same defense-in-depth as Recall\PushController ----

    public function test_a_secret_in_the_message_is_rejected_with_422_and_nothing_is_persisted(): void
    {
        $this->postJson('/v1/reports', $this->validPayload(['message' => 'Prod key is AKIAIOSFODNN7EXAMPLE']))
            ->assertStatus(422);
        $this->assertSame(0, ErrorReport::count());
    }

    public function test_a_secret_in_the_stack_trace_is_rejected(): void
    {
        $this->postJson('/v1/reports', $this->validPayload(['stack_trace' => 'token=AKIAIOSFODNN7EXAMPLE']))
            ->assertStatus(422);
        $this->assertSame(0, ErrorReport::count());
    }

    // Regression (2026-09-23): a real multi-frame stack trace used to be
    // rejected almost every time by the full entropy scan — the payload's
    // own '$this->validPayload()' stack_trace fixture was too short/synthetic
    // to catch this. containsKnownSecretPattern (narrow, no-entropy) fixed it.
    public function test_a_real_multi_frame_stack_trace_with_no_secret_is_accepted(): void
    {
        try {
            throw new \RuntimeException('a real crash');
        } catch (\RuntimeException $e) {
            $trace = $e->getMessage() . "\n" . $e->getTraceAsString();
        }

        $this->postJson('/v1/reports', $this->validPayload(['stack_trace' => $trace]))
            ->assertStatus(201);
        $this->assertSame(1, ErrorReport::count());
    }

    // ---- bounds ----

    public function test_an_oversized_message_is_rejected(): void
    {
        $this->postJson('/v1/reports', $this->validPayload(['message' => str_repeat('a', 10001)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('message');
    }

    public function test_an_oversized_stack_trace_is_rejected(): void
    {
        $this->postJson('/v1/reports', $this->validPayload(['stack_trace' => str_repeat('a', 20001)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('stack_trace');
    }

    public function test_metadata_is_stored_as_an_array(): void
    {
        $this->postJson('/v1/reports', $this->validPayload(['metadata' => ['node' => 'v20.0.0', 'arch' => 'arm64']]))
            ->assertStatus(201);

        $this->assertSame(['node' => 'v20.0.0', 'arch' => 'arm64'], ErrorReport::first()->metadata);
    }

    // ---- metadata: bounded + scanned (security review, 2026-09-23) ----

    public function test_a_secret_in_metadata_is_rejected_and_nothing_is_persisted(): void
    {
        $this->postJson('/v1/reports', $this->validPayload(['metadata' => ['token' => 'AKIAIOSFODNN7EXAMPLE']]))
            ->assertStatus(422);
        $this->assertSame(0, ErrorReport::count());
    }

    public function test_metadata_with_more_than_20_keys_is_rejected(): void
    {
        $metadata = [];
        for ($i = 0; $i < 21; $i++) {
            $metadata["key{$i}"] = 'v';
        }

        $this->postJson('/v1/reports', $this->validPayload(['metadata' => $metadata]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('metadata');
    }

    public function test_a_metadata_value_over_500_chars_is_rejected(): void
    {
        $this->postJson('/v1/reports', $this->validPayload(['metadata' => ['note' => str_repeat('a', 501)]]))
            ->assertStatus(422);
    }

    public function test_a_non_scalar_metadata_value_is_rejected(): void
    {
        $this->postJson('/v1/reports', $this->validPayload(['metadata' => ['nested' => ['a' => 'b']]]))
            ->assertStatus(422);
    }

    public function test_no_actor_or_group_is_ever_recorded(): void
    {
        $this->postJson('/v1/reports', $this->validPayload())->assertStatus(201);

        $report = ErrorReport::first();
        $this->assertArrayNotHasKey('user_id', $report->getAttributes());
        $this->assertArrayNotHasKey('group_id', $report->getAttributes());
    }
}
