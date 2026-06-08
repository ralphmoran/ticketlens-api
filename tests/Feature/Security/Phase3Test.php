<?php

namespace Tests\Feature\Security;

use App\Enums\Permission;
use App\Models\BriefTemplate;
use App\Models\Feature;
use App\Models\Group;
use App\Models\License;
use App\Models\User;
use App\Policies\BriefTemplatePolicy;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Feature tests for Security Audit Phase 3 (MEDIUM/LOW findings).
 * Each test FAILS before the fix is applied, PASSES after.
 */
class Phase3Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Clear per-email rate limiter state between tests.
        RateLimiter::clear('login-by-email:test@example.com');
    }

    // ── M2: Laravel Policies registered ─────────────────────────────────────

    public function test_brief_template_policy_is_registered(): void
    {
        $policy = Gate::getPolicyFor(new BriefTemplate());
        $this->assertInstanceOf(BriefTemplatePolicy::class, $policy);
    }

    public function test_owner_can_update_any_template_via_policy(): void
    {
        $owner = User::factory()->create(['is_owner' => true, 'tier' => 'owner']);

        $template = BriefTemplate::create([
            'group_id'   => null,
            'slug'       => 'any-template',
            'name'       => 'Some Template',
            'sections'   => $this->sections(),
            'is_system'  => false,
            'created_by' => null,
        ]);

        $this->actingAs($owner)
            ->put("/console/admin/templates/{$template->id}", [
                'name'     => 'Owner Updated',
                'sections' => $this->sections(),
            ])
            ->assertRedirect('/console/admin/templates');
    }

    // ── M4: Per-email login rate limit ───────────────────────────────────────

    public function test_login_blocked_after_five_attempts_same_email_different_ips(): void
    {
        User::factory()->create(['email' => 'test@example.com', 'password' => bcrypt('password')]);

        // Five attempts from IP 1.2.3.4 — wrong password, exhausts per-email limit.
        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '1.2.3.4'])
                ->post('/console/login', [
                    'email'    => 'test@example.com',
                    'password' => 'wrong',
                ]);
        }

        // Sixth attempt from different IP — IP throttle not triggered, email throttle must fire.
        $response = $this->withServerVariables(['REMOTE_ADDR' => '9.9.9.9'])
            ->post('/console/login', [
                'email'    => 'test@example.com',
                'password' => 'wrong',
            ]);

        $response->assertStatus(429);
    }

    // ── M5: HSTS only in production ──────────────────────────────────────────

    public function test_hsts_absent_in_staging_environment(): void
    {
        $original = app()->environment();

        try {
            app()->detectEnvironment(fn () => 'staging');
            config(['app.env' => 'staging']);
            $this->assertFalse(app()->isProduction());

            $response = $this->get('/console/login');
            $response->assertHeaderMissing('Strict-Transport-Security');
        } finally {
            app()->detectEnvironment(fn () => $original);
            config(['app.env' => $original]);
        }
    }

    public function test_hsts_present_in_production_environment(): void
    {
        $original = app()->environment();

        try {
            app()->detectEnvironment(fn () => 'production');
            config(['app.env' => 'production']);

            $response = $this->get('/console/login');
            $response->assertHeader('Strict-Transport-Security');
        } finally {
            app()->detectEnvironment(fn () => $original);
            config(['app.env' => $original]);
        }
    }

    // ── M6: is_owner unique constraint ───────────────────────────────────────

    public function test_only_one_is_owner_true_row_allowed(): void
    {
        User::factory()->create(['is_owner' => true]);

        // App-level guard (User::saving) throws RuntimeException; MySQL-level guard
        // throws QueryException via the generated-column unique index added by M6 migration.
        $this->expectException(\RuntimeException::class);
        User::factory()->create(['is_owner' => true]);
    }

    // ── M7: FeatureSeeder has AttentionQueue and TeamViewHealth ──────────────

    public function test_feature_seeder_includes_attention_queue_bit(): void
    {
        (new FeatureSeeder())->run();
        $this->assertDatabaseHas('features', ['bit_value' => 512, 'name' => 'attention_queue']);
    }

    public function test_feature_seeder_includes_team_view_health_bit(): void
    {
        (new FeatureSeeder())->run();
        $this->assertDatabaseHas('features', ['bit_value' => 1024, 'name' => 'team_view_health']);
    }

    public function test_attention_queue_in_team_tier_preset(): void
    {
        (new FeatureSeeder())->run();
        $feature = Feature::where('bit_value', 512)->firstOrFail();
        $this->assertDatabaseHas('tier_features', ['tier' => 'team', 'feature_id' => $feature->id]);
    }

    // ── L3: /s/{token} route has rate limit middleware ───────────────────────

    public function test_share_page_route_has_throttle_middleware(): void
    {
        $route = Route::getRoutes()->getByName('triage.share');
        $this->assertNotNull($route, 'triage.share route must exist');

        $middleware = $route->gatherMiddleware();
        $this->assertTrue(
            in_array('throttle:30,1', $middleware, true),
            'triage.share must have throttle:30,1 middleware — got: ' . implode(', ', $middleware)
        );
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function sections(): array
    {
        return [
            'meta'        => true,
            'description' => true,
            'comments'    => ['enabled' => true, 'max' => 5],
            'linked'      => false,
            'code_refs'   => false,
            'confluence'  => false,
            'attachments' => false,
        ];
    }
}
