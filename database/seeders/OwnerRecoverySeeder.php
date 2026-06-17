<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Break-glass seeder: ensures the platform owner account always exists.
 *
 * Idempotent — safe to run multiple times. Creates the owner only when absent.
 * Reads from config/owner.php (backed by OWNER_* env vars) so it works correctly
 * under php artisan config:cache (env() returns null when cache is active).
 * On every run, updateOrCreate re-applies the password from config — intentional:
 * this is break-glass; it resets credentials to the env-configured value.
 *
 * Run: php artisan db:seed --class=OwnerRecoverySeeder
 */
class OwnerRecoverySeeder extends Seeder
{
    public function run(): void
    {
        $email    = config('owner.email');
        $password = config('owner.password');

        $owner = User::updateOrCreate(
            ['is_owner' => true],
            [
                'name'              => config('owner.name'),
                'email'             => $email,
                'password'          => $password,
                'tier'              => 'owner',
                'permissions'       => 0,
                'is_owner'          => true,
                'email_verified_at' => now(),
            ],
        );

        $action = $owner->wasRecentlyCreated ? 'created' : 'verified';

        $this->command?->info(sprintf('  Owner account %s: %s', $action, $owner->email));
    }
}
