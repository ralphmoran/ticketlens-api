<?php

namespace Tests\Feature\PublicApi;

use App\Enums\Permission;
use App\Jobs\DeliverWebhookJob;
use App\Models\CliToken;
use App\Models\TriageSnapshot;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\WebhookDispatcher;
use App\Services\WebhookUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class WebhookDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['93.184.216.34']));
    }

    private function endpoint(User $user, array $attributes = []): WebhookEndpoint
    {
        return WebhookEndpoint::create($attributes + [
            'user_id' => $user->id,
            'url'     => 'https://hooks.example.com/tl',
            'secret'  => self::SECRET,
        ]);
    }

    private function deliver(WebhookEndpoint $endpoint): void
    {
        (new DeliverWebhookJob($endpoint->id, 'triage.pushed', ['snapshot_id' => 7, 'profile' => 'default', 'ticket_count' => 3], 'dlv-1'))->handle(app(WebhookUrlGuard::class));
    }

    // ── Dispatch on triage push ───────────────────────────────────────────────

    public function test_triage_push_queues_one_delivery_per_active_endpoint(): void
    {
        Queue::fake();
        $user = User::factory()->create(['tier' => 'team', 'permissions' => Permission::team()]);
        $this->endpoint($user);
        $this->endpoint($user, ['url' => 'https://other.example.com/tl']);
        $this->endpoint($user, ['url' => 'https://off.example.com/tl', 'disabled_at' => now()]);

        $this->pushTriage($user)->assertOk();

        Queue::assertPushed(DeliverWebhookJob::class, 2);
    }

    public function test_dispatcher_queues_nothing_for_downgraded_or_suspended_accounts(): void
    {
        Queue::fake();
        $free      = User::factory()->create(['tier' => 'free']);
        $suspended = User::factory()->create(['tier' => 'pro', 'suspended_at' => now()]);
        $this->endpoint($free);
        $this->endpoint($suspended);

        foreach ([$free, $suspended] as $user) {
            $snapshot = TriageSnapshot::create([
                'user_id' => $user->id, 'profile' => 'default', 'tickets' => [], 'ticket_count' => 0, 'captured_at' => now(),
            ]);
            app(WebhookDispatcher::class)->triagePushed($user, $snapshot);
        }

        Queue::assertNotPushed(DeliverWebhookJob::class);
    }

    public function test_triage_push_does_not_notify_other_users_endpoints(): void
    {
        Queue::fake();
        $this->endpoint(User::factory()->create(['tier' => 'pro']));

        $this->pushTriage(User::factory()->create(['tier' => 'team', 'permissions' => Permission::team()]))->assertOk();

        Queue::assertNotPushed(DeliverWebhookJob::class);
    }

    public function test_a_failing_dispatcher_never_breaks_the_triage_push(): void
    {
        $this->app->instance(WebhookDispatcher::class, new class extends WebhookDispatcher {
            public function triagePushed(User $user, TriageSnapshot $snapshot): void
            {
                throw new RuntimeException('queue down');
            }
        });

        $this->pushTriage(User::factory()->create(['tier' => 'team', 'permissions' => Permission::team()]))
            ->assertOk()
            ->assertJson(['pushed' => true]);
    }

    // ── Delivery ──────────────────────────────────────────────────────────────

    public function test_delivery_posts_signed_metadata_only_payload(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response('', 204)]);
        $endpoint = $this->endpoint(User::factory()->create(['tier' => 'pro']));

        $this->deliver($endpoint);

        Http::assertSent(function (Request $request) {
            $timestamp = $request->header('TicketLens-Timestamp')[0] ?? '';
            $expected  = hash_hmac('sha256', $timestamp . '.' . $request->body(), self::SECRET);
            $body      = json_decode($request->body(), true);

            return $request->url() === 'https://hooks.example.com/tl'
                && ($request->header('TicketLens-Signature')[0] ?? null) === 'sha256=' . $expected
                && ($request->header('TicketLens-Event')[0] ?? null) === 'triage.pushed'
                && ($request->header('TicketLens-Delivery')[0] ?? null) === 'dlv-1'
                && $body['event'] === 'triage.pushed'
                && $body['id'] === 'dlv-1'
                && $body['data'] === ['snapshot_id' => 7, 'profile' => 'default', 'ticket_count' => 3]
                && ! str_contains($request->body(), 'tickets"');
        });
    }

    public function test_successful_delivery_resets_failures_and_records_time(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $endpoint = $this->endpoint(User::factory()->create(['tier' => 'pro']), ['failure_count' => 4]);

        $this->deliver($endpoint);

        $endpoint->refresh();
        $this->assertSame(0, $endpoint->failure_count);
        $this->assertNotNull($endpoint->last_delivered_at);
    }

    public function test_non_2xx_response_throws_so_the_queue_retries(): void
    {
        Http::fake(['*' => Http::response('', 502)]);
        $endpoint = $this->endpoint(User::factory()->create(['tier' => 'pro']));

        $this->expectException(RuntimeException::class);

        $this->deliver($endpoint);
    }

    public function test_redirects_are_not_followed(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/'])]);
        $endpoint = $this->endpoint(User::factory()->create(['tier' => 'pro']));

        try {
            $this->deliver($endpoint);
            $this->fail('302 must count as a failed delivery');
        } catch (RuntimeException) {
            Http::assertSentCount(1);
        }
    }

    public function test_delivery_is_skipped_for_disabled_endpoint(): void
    {
        Http::fake();
        $endpoint = $this->endpoint(User::factory()->create(['tier' => 'pro']), ['disabled_at' => now()]);

        $this->deliver($endpoint);

        Http::assertNothingSent();
    }

    public function test_delivery_is_skipped_when_owner_is_downgraded_or_suspended(): void
    {
        Http::fake();
        $free      = $this->endpoint(User::factory()->create(['tier' => 'free']));
        $suspended = $this->endpoint(User::factory()->create(['tier' => 'pro', 'suspended_at' => now()]));

        $this->deliver($free);
        $this->deliver($suspended);

        Http::assertNothingSent();
    }

    public function test_delivery_revalidates_url_at_send_time_and_retries_instead_of_failing_fast(): void
    {
        Http::fake();
        $endpoint = $this->endpoint(User::factory()->create(['tier' => 'pro']));
        $this->app->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['169.254.169.254']));

        try {
            $this->deliver($endpoint);
            $this->fail('a guard rejection must throw so the queue retries');
        } catch (RuntimeException) {
            Http::assertNothingSent();
            $this->assertSame(0, $endpoint->fresh()->failure_count, 'only exhausted retries charge a failure');
        }
    }

    // ── Failure handling ──────────────────────────────────────────────────────

    public function test_exhausted_delivery_increments_failures_and_disables_at_threshold(): void
    {
        $endpoint = $this->endpoint(User::factory()->create(['tier' => 'pro']), ['failure_count' => WebhookEndpoint::DISABLE_AFTER_FAILURES - 1]);
        $job      = new DeliverWebhookJob($endpoint->id, 'triage.pushed', [], 'dlv-9');

        $job->failed(new RuntimeException('boom'));

        $endpoint->refresh();
        $this->assertSame(WebhookEndpoint::DISABLE_AFTER_FAILURES, $endpoint->failure_count);
        $this->assertNotNull($endpoint->disabled_at);
    }

    public function test_failures_accumulate_atomically_and_never_re_enable_a_disabled_endpoint(): void
    {
        $endpoint = $this->endpoint(User::factory()->create(['tier' => 'pro']), [
            'failure_count' => WebhookEndpoint::DISABLE_AFTER_FAILURES,
            'disabled_at'   => now()->subHour(),
        ]);
        $stale = WebhookEndpoint::find($endpoint->id);

        $endpoint->recordFailure();
        $stale->recordFailure();

        $fresh = $endpoint->fresh();
        $this->assertSame(WebhookEndpoint::DISABLE_AFTER_FAILURES + 2, $fresh->failure_count);
        $this->assertNotNull($fresh->disabled_at);
    }

    public function test_job_retries_with_backoff(): void
    {
        $job = new DeliverWebhookJob(1, 'triage.pushed', [], 'dlv-1');

        $this->assertSame(5, $job->tries);
        $this->assertSame(30, $job->timeout);
        $this->assertSame([10, 60, 300, 1800], $job->backoff());
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function pushTriage(User $user)
    {
        $plaintext = 'tl_' . str_repeat('b', 40);
        CliToken::create([
            'user_id'    => $user->id,
            'name'       => 'CLI Token',
            'token_hash' => CliToken::hashToken($plaintext),
        ]);

        return $this->withToken($plaintext)->postJson('/v1/triage/push', [
            'profile'     => 'production',
            'captured_at' => now()->toIso8601ZuluString(),
            'tickets'     => [[
                'key'                 => 'PROJ-123',
                'summary'             => 'Fix login page',
                'status'              => 'Code Review',
                'assignee'            => 'John Doe',
                'attention_score'     => 8.5,
                'flags'               => ['needs-response'],
                'compliance_coverage' => null,
                'compliance_status'   => 'unknown',
                'url'                 => 'https://jira.example.com/browse/PROJ-123',
                'last_updated'        => '2026-05-10T09:00:00Z',
            ]],
        ]);
    }
}
