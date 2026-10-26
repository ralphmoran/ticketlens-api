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

    public function test_spec_documents_the_triage_pushed_webhook_and_its_signature_headers(): void
    {
        $spec      = $this->spec();
        $operation = $spec['webhooks']['triage.pushed']['post'] ?? null;

        $this->assertNotNull($operation, 'webhooks.triage.pushed.post is missing');
        $this->assertSame('#/components/schemas/TriagePushedEvent', $operation['requestBody']['content']['application/json']['schema']['$ref']);

        $headers = array_column($operation['parameters'], 'name');
        foreach (['TicketLens-Signature', 'TicketLens-Timestamp', 'TicketLens-Event', 'TicketLens-Delivery'] as $header) {
            $this->assertContains($header, $headers);
        }

        $event = $spec['components']['schemas']['TriagePushedEvent']['properties'];
        $this->assertEqualsCanonicalizing(['id', 'event', 'created_at', 'data'], array_keys($event));
        $this->assertEqualsCanonicalizing(
            ['snapshot_id', 'profile', 'ticket_count', 'captured_at'],
            array_keys($event['data']['properties']),
            'webhook data fields must match WebhookDispatcher::triagePushed()',
        );
    }

    public function test_spec_is_served_publicly_without_a_key(): void
    {
        $this->getJson('/' . self::SPEC_URI)
            ->assertOk()
            ->assertJson($this->spec());
    }

    public function test_spec_is_cacheable_and_revalidates_with_an_etag(): void
    {
        $first = $this->getJson('/' . self::SPEC_URI)->assertOk();
        $etag  = $first->headers->get('ETag');

        $this->assertNotEmpty($etag);
        $this->assertStringContainsString('max-age', $first->headers->get('Cache-Control'));

        $this->getJson('/' . self::SPEC_URI, ['If-None-Match' => $etag])->assertStatus(304);
    }

    public function test_recall_note_input_documents_attachments_like_the_request_validates_them(): void
    {
        $input = $this->spec()['components']['schemas']['RecallNoteInput']['properties'];

        $this->assertSame(['filename', 'content'], $input['attachments']['items']['required']);
    }
}
