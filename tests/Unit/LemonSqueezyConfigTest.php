<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class LemonSqueezyConfigTest extends TestCase
{
    private const ENV_KEY = 'LEMON_SQUEEZY_WEBHOOK_SECRET';

    protected function tearDown(): void
    {
        unset($_SERVER[self::ENV_KEY], $_ENV[self::ENV_KEY]);
        parent::tearDown();
    }

    public function test_webhook_signing_secret_is_read_from_the_documented_env_variable(): void
    {
        $_SERVER[self::ENV_KEY] = $_ENV[self::ENV_KEY] = 'whsec_from_env';

        $services = require dirname(__DIR__, 2) . '/config/services.php';

        $this->assertSame('whsec_from_env', $services['lemonsqueezy']['signing_secret'] ?? null);
    }

    public function test_every_config_key_the_webhook_controller_reads_exists(): void
    {
        $services = require dirname(__DIR__, 2) . '/config/services.php';

        $this->assertArrayHasKey('signing_secret', $services['lemonsqueezy']);
    }
}
