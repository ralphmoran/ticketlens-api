<?php
namespace Tests\Unit;

use App\Models\DigestSchedule;
use PHPUnit\Framework\TestCase;

class DigestScheduleTest extends TestCase
{
    public function test_hash_key_returns_sha256_hex(): void
    {
        $hash = DigestSchedule::hashKey('test-license-key');
        $this->assertEquals(hash('sha256', 'test-license-key'), $hash);
        $this->assertEquals(64, strlen($hash));
    }

    public function test_hash_key_is_deterministic(): void
    {
        $this->assertEquals(
            DigestSchedule::hashKey('same-key'),
            DigestSchedule::hashKey('same-key')
        );
    }

    public function test_different_keys_produce_different_hashes(): void
    {
        $this->assertNotEquals(
            DigestSchedule::hashKey('key-a'),
            DigestSchedule::hashKey('key-b')
        );
    }
}
