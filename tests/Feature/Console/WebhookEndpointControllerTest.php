<?php

namespace Tests\Feature\Console;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\WebhookUrlGuard;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class WebhookEndpointControllerTest extends TestCase
{
    use RefreshDatabase;

    private Closure $receiver;

    protected function setUp(): void
    {
        parent::setUp();

        // No real DNS in tests: every hostname resolves to a public address.
        $this->app->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['93.184.216.34']));

        // Default receiver is cooperative: it echoes the handshake challenge back. Tests swap it via receiverAnswers().
        $this->receiver = fn (Request $request) => Http::response(['challenge' => $request['data']['challenge'] ?? null]);
        Http::fake(fn (Request $request) => ($this->receiver)($request));
    }

    private function receiverAnswers(Closure $receiver): void
    {
        $this->receiver = $receiver;
    }

    private function proUser(): User
    {
        return User::factory()->create(['tier' => 'pro']);
    }

    private function makeEndpoint(User $user, array $attributes = []): WebhookEndpoint
    {
        return WebhookEndpoint::create($attributes + [
            'user_id' => $user->id,
            'url'     => 'https://hooks.example.com/existing',
            'secret'  => 'whsec_' . bin2hex(random_bytes(8)),
        ]);
    }

    public function test_index_lists_only_the_users_own_endpoints_without_secrets(): void
    {
        $user = $this->proUser();
        $this->makeEndpoint($user, ['url' => 'https://hooks.example.com/mine']);
        $this->makeEndpoint(User::factory()->create(), ['url' => 'https://hooks.example.com/theirs']);

        $this->actingAs($user)->get('/console/api-keys')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->has('webhooks', 1)
                ->where('webhooks.0.url', 'https://hooks.example.com/mine')
                ->missing('webhooks.0.secret')
                ->where('maxWebhooks', WebhookEndpoint::MAX_PER_USER),
        );
    }

    public function test_store_creates_endpoint_and_flashes_signing_secret_once(): void
    {
        $user = $this->proUser();

        $response = $this->actingAs($user)->post('/console/webhooks', ['url' => 'https://hooks.example.com/tl']);

        $response->assertRedirect();
        $response->assertSessionHas('webhook_secret_generated', fn ($secret) => str_starts_with($secret, 'whsec_'));

        $endpoint = $user->webhookEndpoints()->first();
        $this->assertSame('https://hooks.example.com/tl', $endpoint->url);
        $this->assertSame(session('webhook_secret_generated'), $endpoint->secret);
        $this->assertNotSame(session('webhook_secret_generated'), $endpoint->getRawOriginal('secret'), 'secret must be encrypted at rest');
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $user->id, 'action' => 'webhook_endpoint.created']);
    }

    public function test_flashed_secret_reaches_the_page_props_once(): void
    {
        $this->actingAs($this->proUser())
            ->withSession(['webhook_secret_generated' => 'whsec_example'])
            ->get('/console/api-keys')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('flash.webhook_secret_generated', 'whsec_example'));
    }

    // ── Registration handshake ────────────────────────────────────────────────

    public function test_store_sends_a_signed_verify_event_before_saving(): void
    {
        $user = $this->proUser();

        $this->actingAs($user)->post('/console/webhooks', ['url' => 'https://hooks.example.com/tl']);

        Http::assertSent(function (Request $request) {
            $secret = session('webhook_secret_generated');
            $stamp  = $request->header('TicketLens-Timestamp')[0] ?? '';

            return $request->url() === 'https://hooks.example.com/tl'
                && $request['event'] === 'webhook.verify'
                && strlen($request['data']['challenge']) >= 32
                && ($request->header('TicketLens-Signature')[0] ?? null) === 'sha256=' . hash_hmac('sha256', $stamp . '.' . $request->body(), $secret);
        });
    }

    public function test_store_rejects_a_receiver_that_does_not_echo_the_challenge(): void
    {
        $this->receiverAnswers(fn () => Http::response(['challenge' => 'wrong']));

        $this->actingAs($this->proUser())
            ->post('/console/webhooks', ['url' => 'https://victim.example.com/x'])
            ->assertSessionHasErrors('url');

        $this->assertSame(0, WebhookEndpoint::count());
    }

    public function test_store_rejects_a_receiver_that_answers_non_2xx_or_ignores_the_body(): void
    {
        $user = $this->proUser();

        foreach ([['', 500], ['ok', 200]] as [$body, $status]) {
            $this->receiverAnswers(fn () => Http::response($body, $status));

            $this->actingAs($user)->post('/console/webhooks', ['url' => 'https://victim.example.com/x'])->assertSessionHasErrors('url');
        }

        $this->assertSame(0, WebhookEndpoint::count());
    }

    public function test_store_rejects_an_unreachable_receiver(): void
    {
        $this->receiverAnswers(fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out'));

        $this->actingAs($this->proUser())
            ->post('/console/webhooks', ['url' => 'https://down.example.com/x'])
            ->assertSessionHasErrors('url');

        $this->assertSame(0, WebhookEndpoint::count());
    }

    public function test_store_rejects_a_receiver_whose_response_is_aborted_in_transit(): void
    {
        $this->receiverAnswers(fn () => throw new \GuzzleHttp\Exception\RequestException(
            'Webhook response too large.',
            new \GuzzleHttp\Psr7\Request('POST', 'https://big.example.com/x'),
        ));

        $this->actingAs($this->proUser())
            ->post('/console/webhooks', ['url' => 'https://big.example.com/x'])
            ->assertSessionHasErrors('url');

        $this->assertSame(0, WebhookEndpoint::count());
    }

    public function test_store_makes_no_outbound_call_when_the_url_is_unsafe_or_the_cap_is_reached(): void
    {
        $user = $this->proUser();
        $this->actingAs($user)->post('/console/webhooks', ['url' => 'http://hooks.example.com/x']);

        foreach (range(1, WebhookEndpoint::MAX_PER_USER) as $i) {
            $this->makeEndpoint($user, ['url' => "https://hooks.example.com/{$i}"]);
        }
        $this->actingAs($user)->post('/console/webhooks', ['url' => 'https://hooks.example.com/extra']);

        Http::assertNothingSent();
    }

    // ── Re-enable ─────────────────────────────────────────────────────────────

    public function test_enable_reactivates_own_disabled_endpoint_and_resets_failures(): void
    {
        $user     = $this->proUser();
        $endpoint = $this->makeEndpoint($user, ['failure_count' => WebhookEndpoint::DISABLE_AFTER_FAILURES, 'disabled_at' => now()]);

        $this->actingAs($user)->post("/console/webhooks/{$endpoint->id}/enable")->assertRedirect();

        $fresh = $endpoint->fresh();
        $this->assertNull($fresh->disabled_at);
        $this->assertSame(0, $fresh->failure_count);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $user->id, 'action' => 'webhook_endpoint.enabled']);
    }

    public function test_enable_re_runs_the_handshake_and_keeps_the_endpoint_disabled_when_it_fails(): void
    {
        $user     = $this->proUser();
        $endpoint = $this->makeEndpoint($user, ['failure_count' => 10, 'disabled_at' => now()]);
        $this->receiverAnswers(fn () => Http::response(['challenge' => 'wrong']));

        $this->actingAs($user)->post("/console/webhooks/{$endpoint->id}/enable")->assertSessionHasErrors('enable');

        $fresh = $endpoint->fresh();
        $this->assertNotNull($fresh->disabled_at);
        $this->assertSame(10, $fresh->failure_count);
    }

    public function test_downgraded_user_cannot_re_enable_an_endpoint(): void
    {
        $user     = User::factory()->create(['tier' => 'free']);
        $endpoint = $this->makeEndpoint($user, ['disabled_at' => now()]);

        $this->actingAs($user)->post("/console/webhooks/{$endpoint->id}/enable");

        $this->assertNotNull($endpoint->fresh()->disabled_at);
    }

    public function test_enable_cannot_touch_another_users_endpoint(): void
    {
        $endpoint = $this->makeEndpoint(User::factory()->create(), ['disabled_at' => now()]);

        $this->actingAs($this->proUser())->post("/console/webhooks/{$endpoint->id}/enable")->assertNotFound();

        $this->assertNotNull($endpoint->fresh()->disabled_at);
    }

    public function test_index_exposes_disabled_state_so_the_ui_can_offer_re_enable(): void
    {
        $user = $this->proUser();
        $this->makeEndpoint($user, ['disabled_at' => now()]);

        $this->actingAs($user)->get('/console/api-keys')->assertInertia(
            fn (AssertableInertia $page) => $page->whereNot('webhooks.0.disabled_at', null),
        );
    }

    public function test_store_rejects_unsafe_url(): void
    {
        $this->app->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['10.0.0.5']));

        $this->actingAs($this->proUser())
            ->post('/console/webhooks', ['url' => 'https://internal.example.com/x'])
            ->assertSessionHasErrors('url');

        $this->assertSame(0, WebhookEndpoint::count());
    }

    public function test_store_rejects_http_url(): void
    {
        $this->actingAs($this->proUser())
            ->post('/console/webhooks', ['url' => 'http://hooks.example.com/x'])
            ->assertSessionHasErrors('url');
    }

    public function test_store_enforces_endpoint_cap(): void
    {
        $user = $this->proUser();
        foreach (range(1, WebhookEndpoint::MAX_PER_USER) as $i) {
            $this->makeEndpoint($user, ['url' => "https://hooks.example.com/{$i}"]);
        }

        $this->actingAs($user)
            ->post('/console/webhooks', ['url' => 'https://hooks.example.com/extra'])
            ->assertSessionHasErrors('url');

        $this->assertSame(WebhookEndpoint::MAX_PER_USER, $user->webhookEndpoints()->count());
    }

    public function test_free_tier_cannot_create_endpoint(): void
    {
        $this->actingAs(User::factory()->create(['tier' => 'free']))
            ->post('/console/webhooks', ['url' => 'https://hooks.example.com/x']);

        $this->assertSame(0, WebhookEndpoint::count());
    }

    public function test_destroy_removes_own_endpoint_and_audits(): void
    {
        $user     = $this->proUser();
        $endpoint = $this->makeEndpoint($user);

        $this->actingAs($user)->delete("/console/webhooks/{$endpoint->id}")->assertRedirect();

        $this->assertDatabaseMissing('webhook_endpoints', ['id' => $endpoint->id]);
        $this->assertTrue(AuditLog::where('action', 'webhook_endpoint.deleted')->where('actor_id', $user->id)->exists());
    }

    public function test_destroy_cannot_touch_another_users_endpoint(): void
    {
        $endpoint = $this->makeEndpoint(User::factory()->create());

        $this->actingAs($this->proUser())->delete("/console/webhooks/{$endpoint->id}")->assertNotFound();

        $this->assertDatabaseHas('webhook_endpoints', ['id' => $endpoint->id]);
    }
}
