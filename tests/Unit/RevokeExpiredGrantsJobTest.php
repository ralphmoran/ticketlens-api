<?php

namespace Tests\Unit;

use App\Jobs\RevokeExpiredGrantsJob;
use App\Models\AuditLog;
use App\Models\Feature;
use App\Models\User;
use App\Models\UserFeatureGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RevokeExpiredGrantsJobTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['is_owner' => true]);
    }

    private function makeFeature(int $bit): Feature
    {
        return Feature::create([
            'name'       => "feature_{$bit}",
            'bit_value'  => $bit,
            'label'      => "Feature {$bit}",
            'sort_order' => $bit,
        ]);
    }

    private function grant(User $user, Feature $feature, ?string $expiresAt = null): UserFeatureGrant
    {
        return UserFeatureGrant::create([
            'user_id'    => $user->id,
            'feature_id' => $feature->id,
            'granted_by' => $this->owner->id,
            'expires_at' => $expiresAt,
        ]);
    }

    public function test_expired_grants_are_marked_revoked(): void
    {
        $user    = User::factory()->create(['permissions' => 1, 'tier' => 'free']);
        $feature = $this->makeFeature(2);
        $grant   = $this->grant($user, $feature, now()->subMinute()->toDateTimeString());

        app()->call([new RevokeExpiredGrantsJob(), 'handle']);

        $this->assertNotNull($grant->fresh()->revoked_at);
    }

    public function test_non_expired_grants_are_not_revoked(): void
    {
        $user    = User::factory()->create(['permissions' => 1, 'tier' => 'free']);
        $feature = $this->makeFeature(2);
        $grant   = $this->grant($user, $feature, now()->addHour()->toDateTimeString());

        app()->call([new RevokeExpiredGrantsJob(), 'handle']);

        $this->assertNull($grant->fresh()->revoked_at);
    }

    public function test_grants_with_no_expiry_are_not_revoked(): void
    {
        $user    = User::factory()->create(['permissions' => 1, 'tier' => 'free']);
        $feature = $this->makeFeature(2);
        $grant   = $this->grant($user, $feature, null);

        app()->call([new RevokeExpiredGrantsJob(), 'handle']);

        $this->assertNull($grant->fresh()->revoked_at);
    }

    public function test_already_revoked_grants_are_not_updated_again(): void
    {
        $user    = User::factory()->create(['permissions' => 1, 'tier' => 'free']);
        $feature = $this->makeFeature(2);
        $grant   = $this->grant($user, $feature, now()->subHour()->toDateTimeString());

        $firstRevocation = now()->subMinutes(30);
        UserFeatureGrant::where('id', $grant->id)->update(['revoked_at' => $firstRevocation]);

        app()->call([new RevokeExpiredGrantsJob(), 'handle']);

        // revoked_at should remain the original timestamp — not updated again
        $this->assertEquals(
            $firstRevocation->toDateTimeString(),
            $grant->fresh()->revoked_at->toDateTimeString(),
        );
    }

    public function test_revocation_creates_audit_log_entry(): void
    {
        $user    = User::factory()->create(['permissions' => 1, 'tier' => 'free']);
        $feature = $this->makeFeature(2);
        $this->grant($user, $feature, now()->subMinute()->toDateTimeString());

        app()->call([new RevokeExpiredGrantsJob(), 'handle']);

        $this->assertDatabaseHas('audit_logs', [
            'target_user_id' => $user->id,
            'action'         => 'grant.auto_revoked',
        ]);
    }

    public function test_job_is_idempotent_for_audit_logs(): void
    {
        $user    = User::factory()->create(['permissions' => 1, 'tier' => 'free']);
        $feature = $this->makeFeature(2);
        $this->grant($user, $feature, now()->subMinute()->toDateTimeString());

        app()->call([new RevokeExpiredGrantsJob(), 'handle']);
        app()->call([new RevokeExpiredGrantsJob(), 'handle']); // second run

        // Only one audit log entry — already-revoked grants are skipped
        $this->assertSame(1, AuditLog::where('action', 'grant.auto_revoked')
            ->where('target_user_id', $user->id)
            ->count());
    }
}
