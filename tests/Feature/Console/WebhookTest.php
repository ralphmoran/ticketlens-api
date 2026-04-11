<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'test-secret';
    private const WEBHOOK_URL    = '/webhooks/lemonsqueezy';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.lemonsqueezy.signing_secret' => self::WEBHOOK_SECRET]);
    }

    private function sign(string $payload): string
    {
        return 'sha256=' . hash_hmac('sha256', $payload, self::WEBHOOK_SECRET);
    }

    private function postSigned(array $data): \Illuminate\Testing\TestResponse
    {
        $payload = json_encode($data);

        return $this->postJson(self::WEBHOOK_URL, $data, [
            'X-Signature' => $this->sign($payload),
        ]);
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $response = $this->postJson(self::WEBHOOK_URL, ['meta' => ['event_name' => 'subscription_created']], [
            'X-Signature' => 'sha256=invalidsignature',
        ]);

        $response->assertStatus(403);
    }

    public function test_webhook_rejects_missing_signature(): void
    {
        $response = $this->postJson(self::WEBHOOK_URL, ['meta' => ['event_name' => 'subscription_created']]);

        $response->assertStatus(403);
    }

    public function test_webhook_ignores_non_subscription_events(): void
    {
        $response = $this->postSigned([
            'meta' => ['event_name' => 'order_created'],
        ]);

        $response->assertStatus(200);
    }

    public function test_webhook_activates_pro_subscription(): void
    {
        $user = User::factory()->create(['tier' => 'free', 'permissions' => 64]);

        $data = [
            'meta' => [
                'event_name'  => 'subscription_created',
                'custom_data' => ['user_id' => $user->id],
            ],
            'data' => [
                'attributes' => [
                    'product_name' => 'TicketLens Pro',
                    'user_email'   => $user->email,
                    'identifier'   => 'test-license-key-123',
                ],
            ],
        ];

        $response = $this->postSigned($data);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id'   => $user->id,
            'tier' => 'pro',
        ]);
    }

    public function test_webhook_deactivates_subscription(): void
    {
        $user = User::factory()->create(['tier' => 'pro', 'permissions' => 71]);

        $data = [
            'meta' => [
                'event_name'  => 'subscription_cancelled',
                'custom_data' => ['user_id' => $user->id],
            ],
            'data' => [
                'attributes' => [
                    'user_email' => $user->email,
                ],
            ],
        ];

        $response = $this->postSigned($data);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id'   => $user->id,
            'tier' => 'free',
        ]);
    }
}
