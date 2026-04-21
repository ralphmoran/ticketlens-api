<?php

namespace Tests\Feature\Console\Admin;

use App\Models\Group;
use App\Models\User;
use App\Services\AuditService;
use App\Services\LicenseIssuanceService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * End-to-end integration test covering:
 *   Owner issues Team license
 *   → recipient auto-becomes team manager (owns group + has manager bits)
 *   → recipient invites a teammate
 *   → teammate gets password-reset email (invite)
 *   → teammate appears in the scoped member list
 *   → seat accounting holds
 */
class InviteFlowIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
    }

    public function test_owner_issues_team_license_and_recipient_becomes_manager(): void
    {
        $owner     = User::factory()->create(['is_owner' => true]);
        $recipient = User::factory()->create(['tier' => 'free', 'permissions' => 64]);

        app(LicenseIssuanceService::class)
            ->issue($owner, $recipient, 'team', seats: 3, sendEmail: false);

        $recipient->refresh();

        // Recipient is now team-tier with manager bits (127 | 384 = 511)
        $this->assertSame('team', $recipient->tier);
        $this->assertSame(511, $recipient->permissions);

        // Group was created with recipient as owner and member
        $group = $recipient->ownedGroup;
        $this->assertNotNull($group);
        $this->assertSame($recipient->id, $group->owner_id);
        $this->assertTrue($group->members()->where('users.id', $recipient->id)->exists());
    }

    public function test_enterprise_license_also_creates_group(): void
    {
        $owner     = User::factory()->create(['is_owner' => true]);
        $recipient = User::factory()->create();

        app(LicenseIssuanceService::class)
            ->issue($owner, $recipient, 'enterprise', sendEmail: false);

        $recipient->refresh();
        $this->assertNotNull($recipient->ownedGroup);
        $this->assertTrue($recipient->isTeamManager());
    }

    public function test_pro_license_does_not_create_group(): void
    {
        $owner     = User::factory()->create(['is_owner' => true]);
        $recipient = User::factory()->create();

        app(LicenseIssuanceService::class)
            ->issue($owner, $recipient, 'pro', sendEmail: false);

        $recipient->refresh();
        $this->assertNull($recipient->ownedGroup);
        $this->assertFalse($recipient->isTeamManager());
    }

    public function test_full_invite_flow_end_to_end(): void
    {
        // Stage 1: Owner issues Team license
        $owner   = User::factory()->create(['is_owner' => true]);
        $manager = User::factory()->create(['tier' => 'free', 'permissions' => 64]);

        app(LicenseIssuanceService::class)
            ->issue($owner, $manager, 'team', seats: 3, sendEmail: false);

        $manager->refresh();

        // Stage 2: Manager hits the invite endpoint
        $response = $this->actingAs($manager)->post('/console/admin/members', [
            'email' => 'teammate@example.com',
            'name'  => 'New Teammate',
        ]);

        $response->assertRedirect();

        // Stage 3: Teammate exists in DB
        $teammate = User::where('email', 'teammate@example.com')->firstOrFail();

        // Stage 4: Teammate received the password-reset email
        Notification::assertSentTo($teammate, ResetPassword::class);

        // Stage 5: Teammate appears in the manager's scoped member list
        $response = $this->actingAs($manager)->get('/console/admin/members');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Console/Admin/Members')
            ->where('seats_used', 2)   // manager + teammate
            ->where('seats_total', 3)
            ->where('members', fn ($members) =>
                collect($members)->pluck('email')->contains('teammate@example.com')
            )
        );
    }

    public function test_seat_limit_enforced_at_endpoint(): void
    {
        $owner   = User::factory()->create(['is_owner' => true]);
        $manager = User::factory()->create();

        app(LicenseIssuanceService::class)
            ->issue($owner, $manager, 'team', seats: 1, sendEmail: false);

        $manager->refresh();

        // seats=1, manager already occupies it — any invite exceeds limit
        $response = $this->actingAs($manager)->post('/console/admin/members', [
            'email' => 'overflow@example.com',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('users', ['email' => 'overflow@example.com']);
    }

    public function test_invite_from_foreign_manager_cannot_target_another_team(): void
    {
        // Two independent teams
        $owner = User::factory()->create(['is_owner' => true]);

        $managerA = User::factory()->create();
        app(LicenseIssuanceService::class)->issue($owner, $managerA, 'team', seats: 5, sendEmail: false);

        $managerB = User::factory()->create();
        app(LicenseIssuanceService::class)->issue($owner, $managerB, 'team', seats: 5, sendEmail: false);

        // Manager A invites someone
        $this->actingAs($managerA->fresh())->post('/console/admin/members', [
            'email' => 'member@a.com',
        ]);

        $memberA = User::where('email', 'member@a.com')->first();
        $this->assertNotNull($memberA);

        // Manager B should NOT see member A in their list (scoping)
        $response = $this->actingAs($managerB->fresh())->get('/console/admin/members');
        $response->assertInertia(fn ($page) => $page
            ->where('members', fn ($members) => ! collect($members)->pluck('email')->contains('member@a.com'))
        );
    }
}
