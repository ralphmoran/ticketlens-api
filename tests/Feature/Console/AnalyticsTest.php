<?php

namespace Tests\Feature\Console;

use App\Models\UsageLog;
use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get('/console/analytics');

        $response->assertRedirect('/console/login');
    }

    public function test_free_tier_user_sees_analytics_with_null_stats(): void
    {
        $user = User::factory()->create(['tier' => 'free', 'permissions' => 64]);

        $response = $this->actingAs($user)->get('/console/analytics');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Console/Analytics')
            ->where('tier', 'free')
            ->where('stats', null)
            ->has('daily')
        );
    }

    public function test_pro_tier_user_sees_analytics_with_stats(): void
    {
        $user = User::factory()->create(['tier' => 'pro', 'permissions' => 71]);

        $response = $this->actingAs($user)->get('/console/analytics');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Console/Analytics')
            ->where('tier', 'pro')
            ->has('stats')
            ->has('stats.totalTokens')
            ->has('stats.totalCalls')
            ->has('stats.byAction')
            ->has('daily')
        );
    }

    // ── LOCK: BYOK rows (metadata null) still counted in totalTokens ──────────

    public function test_lock_byok_rows_counted_in_total_tokens(): void
    {
        $user = User::factory()->create(['tier' => 'pro', 'permissions' => 71]);
        UsageLog::create(['user_id' => $user->id, 'action' => 'digest', 'ticket_key' => null, 'tokens_used' => 500]);

        $response = $this->actingAs($user)->get('/console/analytics');

        $response->assertInertia(fn ($page) => $page
            ->where('stats.totalTokens', 500)
        );
    }

    // ── RED → GREEN: CLI rows (metadata not null) excluded from totalTokens ───

    public function test_analytics_excludes_cli_command_rows_from_total_tokens(): void
    {
        $user = User::factory()->create(['tier' => 'pro', 'permissions' => 71]);
        // BYOK row — should be counted
        UsageLog::create(['user_id' => $user->id, 'action' => 'digest', 'ticket_key' => null, 'tokens_used' => 400]);
        // CLI command row (metadata not null) — must be excluded
        \Illuminate\Support\Facades\DB::table('usage_logs')->insert([
            'user_id'     => $user->id,
            'action'      => 'fetch',
            'ticket_key'  => null,
            'tokens_used' => 9999,
            'metadata'    => json_encode(['count' => 1, 'flags' => []]),
            'created_at'  => now(),
        ]);

        $response = $this->actingAs($user)->get('/console/analytics');

        $response->assertInertia(fn ($page) => $page
            ->where('stats.totalTokens', 400)
        );
    }
}
