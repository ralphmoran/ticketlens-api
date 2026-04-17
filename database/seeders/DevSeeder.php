<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Dev test accounts — one per access level.
 * All passwords: "password"
 *
 * Run: php artisan db:seed --class=DevSeeder
 *
 * | Email                   | Tier   | Permissions | Can access                          |
 * |-------------------------|--------|-------------|-------------------------------------|
 * | free@test.local         | free   | 64          | SavingsAnalytics only               |
 * | pro@test.local          | pro    | 71          | + Schedules / Digests / Summarize   |
 * | team@test.local         | team   | 127         | + Compliance / Export / MultiAccount|
 * | admin@test.local        | free   | 960         | Admin panels, free-tier features    |
 * | superadmin@test.local   | team   | 1023        | Everything                          |
 */
class DevSeeder extends Seeder
{
    private const PASSWORD = 'password';

    public function run(): void
    {
        $accounts = [
            [
                'name'        => 'Free User',
                'email'       => 'free@test.local',
                'tier'        => 'free',
                'permissions' => Permission::free(),
            ],
            [
                'name'        => 'Pro User',
                'email'       => 'pro@test.local',
                'tier'        => 'pro',
                'permissions' => Permission::pro(),
            ],
            [
                'name'        => 'Team User',
                'email'       => 'team@test.local',
                'tier'        => 'team',
                'permissions' => Permission::team(),
            ],
            [
                'name'        => 'Admin',
                'email'       => 'admin@test.local',
                'tier'        => 'free',
                'permissions' => Permission::free() | Permission::adminMask(),
            ],
            [
                'name'        => 'Super Admin',
                'email'       => 'superadmin@test.local',
                'tier'        => 'team',
                'permissions' => Permission::team() | Permission::adminMask(),
                'is_owner'    => true,
            ],
        ];

        foreach ($accounts as $account) {
            User::updateOrCreate(
                ['email' => $account['email']],
                [
                    'name'        => $account['name'],
                    'password'    => self::PASSWORD,
                    'tier'        => $account['tier'],
                    'permissions' => $account['permissions'],
                    'is_owner'    => $account['is_owner'] ?? false,
                ],
            );

            $this->command->info("  {$account['email']} (tier={$account['tier']}, permissions={$account['permissions']}, is_owner=" . ($account['is_owner'] ?? false ? 'true' : 'false') . ')');
        }
    }
}
