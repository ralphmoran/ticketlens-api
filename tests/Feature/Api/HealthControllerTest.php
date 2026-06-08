<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class HealthControllerTest extends TestCase
{
    public function test_health_returns_200_with_ok_status(): void
    {
        $this->getJson('/v1/health')
            ->assertStatus(200)
            ->assertExactJson(['status' => 'ok']);
    }

    public function test_health_requires_no_authentication(): void
    {
        $this->getJson('/v1/health')->assertStatus(200);
    }

    public function test_health_does_not_expose_version(): void
    {
        $response = $this->getJson('/v1/health')->assertStatus(200);
        $this->assertArrayNotHasKey('version', $response->json());
    }
}
