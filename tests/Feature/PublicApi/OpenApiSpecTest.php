<?php

namespace Tests\Feature\PublicApi;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class OpenApiSpecTest extends TestCase
{
    private const SPEC_URI = 'v1/public/openapi.json';

    private function spec(): array
    {
        return json_decode(file_get_contents(resource_path('openapi/public-v1.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_spec_is_openapi_3_1_with_bearer_auth(): void
    {
        $spec = $this->spec();

        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertSame('bearer', $spec['components']['securitySchemes']['bearerAuth']['scheme']);
        $this->assertSame([['bearerAuth' => []]], $spec['security']);
    }

    public function test_every_public_route_is_documented(): void
    {
        $spec = $this->spec();
        $documented = 0;

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'v1/public/') || $route->uri() === self::SPEC_URI) {
                continue;
            }

            $path = '/' . substr($route->uri(), strlen('v1/public/'));

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $this->assertArrayHasKey(
                    strtolower($method),
                    $spec['paths'][$path] ?? [],
                    "OpenAPI spec is missing {$method} {$path}",
                );
                $documented++;
            }
        }

        $this->assertGreaterThanOrEqual(4, $documented);
    }

    public function test_every_documented_operation_lists_its_required_scope(): void
    {
        foreach ($this->spec()['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                $this->assertNotEmpty($operation['x-required-scopes'] ?? [], "{$method} {$path} has no x-required-scopes");
            }
        }
    }

    public function test_spec_documents_only_scopes_that_exist(): void
    {
        foreach ($this->spec()['paths'] as $operations) {
            foreach ($operations as $operation) {
                foreach ($operation['x-required-scopes'] as $scope) {
                    $this->assertContains($scope, \App\Models\ApiKey::SCOPES);
                }
            }
        }
    }

    public function test_spec_is_served_publicly_without_a_key(): void
    {
        $this->getJson('/' . self::SPEC_URI)
            ->assertOk()
            ->assertJson($this->spec());
    }
}
