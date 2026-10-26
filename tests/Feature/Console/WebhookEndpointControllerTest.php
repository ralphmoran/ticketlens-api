<?php

namespace Tests\Feature\Console;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\WebhookUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class WebhookEndpointControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // No real DNS in tests: every hostname resolves to a public address.
        $this->app->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['93.184.216.34']));
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
