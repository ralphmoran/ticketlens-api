<?php

namespace Tests\Feature\Owner;

use App\Models\User;
use App\Models\AuditLog;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AuditControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeOwner(): User
    {
        return User::factory()->create(['is_owner' => true]);
    }

    private function makeLog(User $actor, User $target, string $action): AuditLog
    {
        return AuditLog::create([
            'actor_id'       => $actor->id,
            'target_user_id' => $target->id,
            'action'         => $action,
            'ip_address'     => '127.0.0.1',
        ]);
    }

    public function test_owner_can_view_audit_log(): void
    {
        $owner = $this->makeOwner();
        $user  = User::factory()->create();
        $this->makeLog($owner, $user, 'user.suspended');

        $response = $this->actingAs($owner)->get('/console/owner/audit');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Console/Owner/Audit/Index')
            ->has('logs.data', 1)
        );
    }

    public function test_non_owner_cannot_view_audit_log(): void
    {
        $user = User::factory()->create(['permissions' => 1023]);

        $response = $this->actingAs($user)->get('/console/owner/audit');

        $response->assertRedirect('/console/dashboard');
    }

    public function test_audit_log_is_paginated(): void
    {
        $owner = $this->makeOwner();
        $user  = User::factory()->create();

        for ($i = 0; $i < 30; $i++) {
            $this->makeLog($owner, $user, 'user.suspended');
        }

        $response = $this->actingAs($owner)->get('/console/owner/audit');

        $response->assertInertia(fn ($page) => $page
            ->has('logs.data', 20) // default 20 per page
        );
    }

    public function test_audit_log_filterable_by_action(): void
    {
        $owner = $this->makeOwner();
        $user  = User::factory()->create();
        $this->makeLog($owner, $user, 'user.suspended');
        $this->makeLog($owner, $user, 'user.tier_changed');

        $response = $this->actingAs($owner)->get('/console/owner/audit?action=user.suspended');

        $response->assertInertia(fn ($page) => $page->has('logs.data', 1));
    }
}
