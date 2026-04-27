<?php

namespace Tests\Feature\Owner;

use App\Models\ImpersonationSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class ImpersonationControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeOwner(): User
    {
        return User::factory()->create(['is_owner' => true]);
    }

    private function makeUser(array $attrs = []): User
    {
        return User::factory()->create(array_merge(
            ['tier' => 'free', 'permissions' => 1, 'is_owner' => false],
            $attrs,
        ));
    }

    // --- Start impersonation ---

    public function test_owner_can_start_impersonation(): void
    {
        $owner  = $this->makeOwner();
        $target = $this->makeUser(['email' => 'target@test.com']);

        $response = $this->actingAs($owner)->post("/console/owner/impersonate/{$target->id}");

        $response->assertRedirect('/console/dashboard');
        $this->assertSame($target->id, Auth::id());
        $this->assertSame($owner->id, session('impersonator_id'));
        $this->assertDatabaseHas('impersonation_sessions', [
            'actor_id'       => $owner->id,
            'target_user_id' => $target->id,
            'ended_at'       => null,
        ]);
    }

    public function test_start_impersonation_logs_audit_with_ip(): void
    {
        $owner  = $this->makeOwner();
        $target = $this->makeUser();

        $this->actingAs($owner)->post("/console/owner/impersonate/{$target->id}");

        $this->assertDatabaseHas('audit_logs', [
            'actor_id'       => $owner->id,
            'target_user_id' => $target->id,
            'action'         => 'impersonation.started',
        ]);
        $log = \DB::table('audit_logs')->where('action', 'impersonation.started')->first();
        $this->assertNotNull($log->ip_address);
    }

    public function test_non_owner_cannot_start_impersonation(): void
    {
        $nonOwner = $this->makeUser(['permissions' => 1023]);
        $target   = $this->makeUser();

        $response = $this->actingAs($nonOwner)->post("/console/owner/impersonate/{$target->id}");

        $response->assertRedirect('/console/dashboard');
        $this->assertDatabaseEmpty('impersonation_sessions');
        $this->assertSame($nonOwner->id, Auth::id());
    }

    public function test_owner_cannot_impersonate_self(): void
    {
        // The is_owner guard fires first for the owner-as-target scenario (403,
        // "platform owner cannot be impersonated"), which subsumes the older
        // ValidationException-based self-impersonation check. Both checks remain
        // — the self-check stays as defense in depth.
        $owner = $this->makeOwner();

        $response = $this->actingAs($owner)->post("/console/owner/impersonate/{$owner->id}");

        $response->assertStatus(403);
        $this->assertNull(session('impersonator_id'));
        $this->assertDatabaseEmpty('impersonation_sessions');
    }

    public function test_cannot_impersonate_owner_target(): void
    {
        $owner = $this->makeOwner();

        // Fabricate a second is_owner row via direct DB to test the controller
        // guard independently of the singleton invariant.
        $protectedTarget = $this->makeUser(['email' => 'protected@test.com']);
        \DB::table('users')->where('id', $protectedTarget->id)->update(['is_owner' => true]);

        $response = $this->actingAs($owner)->post("/console/owner/impersonate/{$protectedTarget->id}");

        $response->assertStatus(403);
        $this->assertNull(session('impersonator_id'));
        $this->assertDatabaseEmpty('impersonation_sessions');
        $this->assertSame($owner->id, Auth::id());
    }

    public function test_owner_cannot_cascade_impersonation(): void
    {
        $owner   = $this->makeOwner();
        $target1 = $this->makeUser(['email' => 't1@test.com']);
        $target2 = $this->makeUser(['email' => 't2@test.com']);

        $this->actingAs($owner)->post("/console/owner/impersonate/{$target1->id}");
        // Now authed as target1, session has impersonator_id=owner. Attempt cascade.
        $response = $this->post("/console/owner/impersonate/{$target2->id}");

        // As target1 (non-owner), the `owner` middleware redirects to /console/dashboard.
        $response->assertRedirect('/console/dashboard');
        $this->assertSame($target1->id, Auth::id());
        // Only one session row — no cascade.
        $this->assertSame(1, ImpersonationSession::count());
    }

    // --- Stop impersonation ---

    public function test_stop_restores_owner_and_clears_session(): void
    {
        $owner  = $this->makeOwner();
        $target = $this->makeUser();

        $this->actingAs($owner)->post("/console/owner/impersonate/{$target->id}");
        $response = $this->delete('/console/impersonate');

        $response->assertRedirect();
        $this->assertSame($owner->id, Auth::id());
        $this->assertNull(session('impersonator_id'));
        $this->assertNotNull(ImpersonationSession::first()->ended_at);
    }

    public function test_stop_logs_audit(): void
    {
        $owner  = $this->makeOwner();
        $target = $this->makeUser();

        $this->actingAs($owner)->post("/console/owner/impersonate/{$target->id}");
        $this->delete('/console/impersonate');

        $this->assertDatabaseHas('audit_logs', [
            'actor_id'       => $owner->id,
            'target_user_id' => $target->id,
            'action'         => 'impersonation.stopped',
        ]);
    }

    public function test_stop_without_active_impersonation_is_forbidden(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user)->delete('/console/impersonate');

        $response->assertForbidden();
        $this->assertDatabaseEmpty('impersonation_sessions');
    }

    public function test_unauthenticated_stop_redirects_to_login(): void
    {
        $response = $this->delete('/console/impersonate');

        $response->assertRedirect('/console/login');
    }

    // --- Inertia auth.impersonating share ---

    public function test_impersonating_is_null_when_not_impersonating(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)->get('/console/dashboard')
            ->assertInertia(fn ($page) => $page->where('auth.impersonating', null));
    }

    public function test_impersonating_contains_target_identity_during_impersonation(): void
    {
        $owner  = $this->makeOwner();
        $target = $this->makeUser(['name' => 'Target User', 'email' => 'target@test.com']);

        $this->actingAs($owner)->post("/console/owner/impersonate/{$target->id}");

        $this->get('/console/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('auth.impersonating.name', 'Target User')
                ->where('auth.impersonating.email', 'target@test.com')
            );
    }

    // --- Logout + target permissions during impersonation ---

    public function test_logout_during_impersonation_clears_session_key(): void
    {
        $owner  = $this->makeOwner();
        $target = $this->makeUser();

        $this->actingAs($owner)->post("/console/owner/impersonate/{$target->id}");
        $this->post('/console/logout');

        $this->assertNull(session('impersonator_id'));
        $this->assertGuest();
    }

    public function test_permission_checks_use_target_during_impersonation(): void
    {
        // Owner has permissions=0. Target has Digests bit (2) but NOT Schedules bit (1).
        // Before impersonation owner lacks both. During impersonation, requests are
        // evaluated against TARGET's bitmask — digests allowed, schedules blocked.
        $owner  = $this->makeOwner();
        $target = $this->makeUser(['permissions' => 2]);

        $this->actingAs($owner)->post("/console/owner/impersonate/{$target->id}");

        $this->get('/console/digests')->assertStatus(200);
        $this->get('/console/schedules')->assertRedirect('/console/upgrade');
    }
}
