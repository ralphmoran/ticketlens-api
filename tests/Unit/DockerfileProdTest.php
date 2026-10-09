<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DockerfileProdTest extends TestCase
{
    private function root(string $path): string
    {
        return dirname(__DIR__, 2) . '/' . $path;
    }

    private function requiredPhpMinor(): string
    {
        $constraint = json_decode(file_get_contents($this->root('composer.json')), true)['require']['php'];
        preg_match('/(\d+\.\d+)/', $constraint, $m);

        return $m[1];
    }

    /** @return string[] */
    private function dockerfilePhpMinors(): array
    {
        preg_match_all('/^FROM\s+php:(\d+\.\d+)/mi', file_get_contents($this->root('Dockerfile.prod')), $m);

        return $m[1];
    }

    public function test_every_php_stage_matches_the_composer_php_requirement(): void
    {
        $minors = $this->dockerfilePhpMinors();

        $this->assertNotEmpty($minors);
        foreach ($minors as $minor) {
            $this->assertSame($this->requiredPhpMinor(), $minor);
        }
    }

    public function test_composer_install_stage_runs_on_the_required_php(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/^FROM\s+composer:/mi',
            file_get_contents($this->root('Dockerfile.prod')),
            'composer:* images ship their own PHP; use php:X-cli plus the composer binary.',
        );
        $this->assertGreaterThanOrEqual(2, count($this->dockerfilePhpMinors()));
    }

    public function test_dockerignore_keeps_host_dev_artifacts_out_of_the_image(): void
    {
        $ignored = array_map('trim', file($this->root('.dockerignore'), FILE_IGNORE_NEW_LINES));

        foreach (['.env.*', 'vendor', 'bootstrap/cache/*.php', 'storage/logs', '.claude', '.idea', 'graphify-out', '.github', 'tests', 'docs'] as $path) {
            $this->assertContains($path, $ignored, "$path must be dockerignored: the host copy overrides the prod build");
        }
    }

    public function test_prod_compose_passes_the_documented_app_secrets_to_the_app_container(): void
    {
        $compose = file_get_contents($this->root('docker-compose.prod.yml'));

        foreach ([
            'LEMON_SQUEEZY_WEBHOOK_SECRET', 'SLACK_CLIENT_ID', 'SLACK_CLIENT_SECRET',
            'SLACK_SIGNING_SECRET', 'SLACK_REDIRECT_URI', 'INERTIA_SSR_ENABLED', 'ENTERPRISE_CONTACT_EMAIL',
        ] as $key) {
            $this->assertMatchesRegularExpression("/^\s+$key:/m", $compose, "$key is documented but never reaches the container");
        }
    }

    public function test_every_container_that_runs_slack_jobs_gets_the_slack_credentials(): void
    {
        $compose = file_get_contents($this->root('docker-compose.prod.yml'));

        // app (OAuth), queue (SendSlackDigestJob) and scheduler share one anchored block.
        $this->assertSame(3, substr_count($compose, '<<: *slack-env'));
    }

    public function test_prod_compose_fails_fast_when_required_secrets_are_unset(): void
    {
        $compose = file_get_contents($this->root('docker-compose.prod.yml'));

        foreach (['LEMON_SQUEEZY_WEBHOOK_SECRET', 'ENTERPRISE_CONTACT_EMAIL'] as $key) {
            $this->assertStringContainsString('${' . $key . ':?', $compose, "$key must abort compose when unset");
        }
    }

    public function test_prod_compose_runs_the_scheduler_as_its_own_service(): void
    {
        $compose = file_get_contents($this->root('docker-compose.prod.yml'));

        $this->assertMatchesRegularExpression('/^  scheduler:/m', $compose);
        $this->assertStringContainsString('php artisan schedule:work', $compose);
    }
}
