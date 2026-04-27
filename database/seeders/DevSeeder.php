<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Dev test accounts — one per customer-facing role, plus one platform-owner account.
 * All passwords: "password"
 *
 * Role model — three independent axes:
 *   - tier           : customer subscription plan (free, pro, team)
 *   - team-manager   : owns a `groups` row; set via `groups.owner_id = user.id`
 *   - platform owner : TicketLens staff; set via `users.is_owner = true`
 *
 * | Email                     | Tier  | is_owner | Owns group? | Sidebar                                        |
 * |---------------------------|-------|:--------:|:-----------:|------------------------------------------------|
 * | free@test.local           | free  |  false   | no          | Overview                                       |
 * | pro@test.local            | pro   |  false   | no          | Overview + Workflow                            |
 * | team-member@test.local    | team  |  false   | no (seat)   | Overview + Workflow + Team                     |
 * | team-manager@test.local   | team  |  false   | yes         | Overview + Workflow + Team + Admin             |
 * | owner@test.local          | owner |  true    | no          | Everything + Owner (god mode via is_owner)     |
 *
 * Run: php artisan db:seed --class=DevSeeder
 * Idempotent — safe to run multiple times.
 */
class DevSeeder extends Seeder
{
    private const PASSWORD = 'password';

    public function run(): void
    {
        // Reference data must exist before users are wired up — the Owner
        // panel's Tiers & Features and Clients > Show pages both render
        // empty if `features` / `tier_features` are not populated.
        $this->call(FeatureSeeder::class);

        $free        = $this->upsertUser('Free User',         'free@test.local',         'free', Permission::free());
        $pro         = $this->upsertUser('Pro User',          'pro@test.local',          'pro',  Permission::pro());
        $teamMember  = $this->upsertUser('Team Member',       'team-member@test.local',  'team', Permission::team());
        $teamManager = $this->upsertUser('Team Manager',      'team-manager@test.local', 'team', Permission::team() | Permission::teamManagerMask());

        // Owner is decoupled from the tier system — tier='owner' is a sentinel,
        // permissions=0 because god mode is granted by is_owner=true via the
        // PermissionService short-circuit. No owned group: owners do not appear
        // in any team's roster.
        $owner = $this->upsertUser('Platform Owner', 'owner@test.local', 'owner', 0, is_owner: true);

        $managerGroup = $this->ensureOwnedGroup($teamManager, "Team Manager's Team");
        $managerGroup->users()->syncWithoutDetaching([$teamManager->id, $teamMember->id]);

        unset($free, $pro, $owner);
    }

    private function upsertUser(string $name, string $email, string $tier, int $permissions, bool $is_owner = false): User
    {
        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name'        => $name,
                'password'    => self::PASSWORD,
                'tier'        => $tier,
                'permissions' => $permissions,
                'is_owner'    => $is_owner,
            ],
        );

        $this->command->info(sprintf(
            '  %s (tier=%s, permissions=%d, is_owner=%s)',
            $email,
            $tier,
            $permissions,
            $is_owner ? 'true' : 'false',
        ));

        return $user;
    }

    private function ensureOwnedGroup(User $owner, string $name): Group
    {
        return Group::firstOrCreate(
            ['owner_id' => $owner->id],
            ['name' => $name, 'permissions' => 0],
        );
    }
}
