<?php

namespace Tests\Feature\Console;

use Tests\TestCase;

class InertiaBootstrapTest extends TestCase
{
    public function test_inertia_test_route_returns_inertia_response(): void
    {
        $response = $this->get('/inertia-test');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Test'));
    }
}
