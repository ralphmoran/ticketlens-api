<?php

namespace Tests\Unit\Models;

use App\Models\UsageLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsageLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_cli_origin_scope_includes_rows_with_metadata(): void
    {
        $user = User::factory()->create();
        $cliRow = UsageLog::create([
            'user_id'       => $user->id,
            'action'        => 'fetch',
            'tokens_used'   => 100,
            'command_count' => 1,
            'metadata'      => ['count' => 1],
        ]);

        $result = UsageLog::cliOrigin()->pluck('id');

        $this->assertTrue($result->contains($cliRow->id));
    }

    public function test_cli_origin_scope_excludes_rows_without_metadata(): void
    {
        $user = User::factory()->create();
        $aiActionRow = UsageLog::create([
            'user_id'     => $user->id,
            'action'      => 'digest',
            'tokens_used' => 500,
            'metadata'    => null,
        ]);

        $result = UsageLog::cliOrigin()->pluck('id');

        $this->assertFalse($result->contains($aiActionRow->id));
    }
}
