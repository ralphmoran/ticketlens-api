<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Models\Group;
use App\Models\License;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SimulateAccountsSeeder extends Seeder
{
    private const GROUP_NAMES = [
        'Team Alpha', 'Team Beta', 'Team Gamma', 'Team Delta',
        'Team Epsilon', 'Team Zeta', 'Team Eta', 'Team Theta',
    ];

    // BYOK actions (metadata IS NULL rows — populate Analytics page)
    private const BYOK_ACTIONS = ['summarize', 'fetch', 'review', 'summarize', 'fetch'];

    public function run(): void
    {
        $groqRaw = $this->readGroqRaw();

        [$freeUsers, $proUsers, $members, $managers] = $this->createUsers();
        $this->createGroups($managers, $members);

        $paidUsers = [...$proUsers, ...$members, ...$managers];
        $this->createLicenses($paidUsers);

        $allUsers = [...$freeUsers, ...$paidUsers];
        $tokens   = $this->createCliTokens($allUsers);
        $this->copyGroqKey($groqRaw, $allUsers);
        $this->createByokRows($paidUsers);

        file_put_contents(
            storage_path('app/sim-tokens.json'),
            json_encode($tokens, JSON_PRETTY_PRINT)
        );

        $this->command->info('Done. Token map → ' . storage_path('app/sim-tokens.json'));
        $this->command->info(sprintf(
            'Created: %d free, %d pro, %d members, %d managers, %d groups, %d licenses',
            count($freeUsers), count($proUsers), count($members), count($managers),
            count(self::GROUP_NAMES), count($paidUsers)
        ));
    }

    // ── Account creation ─────────────────────────────────────────────────────

    private function createUsers(): array
    {
        $teamPerm    = Permission::team();
        $managerPerm = Permission::team() | Permission::teamManagerMask();
        $free = $pro = $members = $managers = [];

        for ($i = 1; $i <= 12; $i++) {
            $free[] = $this->upsert("Free User $i", "free@sim-{$i}.local", 'free', $teamPerm);
        }
        for ($i = 1; $i <= 13; $i++) {
            $pro[] = $this->upsert("Pro User $i", "pro@sim-{$i}.local", 'pro', $teamPerm);
        }
        for ($i = 1; $i <= 17; $i++) {
            $members[] = $this->upsert("Team Member $i", "member@sim-{$i}.local", 'team', $teamPerm);
        }
        for ($i = 1; $i <= 8; $i++) {
            $managers[] = $this->upsert("Team Manager $i", "manager@sim-{$i}.local", 'team', $managerPerm);
        }

        return [$free, $pro, $members, $managers];
    }

    private function upsert(string $name, string $email, string $tier, int $permissions): User
    {
        return User::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => 'password', 'tier' => $tier, 'permissions' => $permissions],
        );
    }

    private function createGroups(array $managers, array $members): void
    {
        // Distribute 17 members across 8 groups via round-robin (group 0 gets 3, rest get 2)
        $buckets = array_fill(0, 8, []);
        foreach ($members as $j => $member) {
            $buckets[$j % 8][] = $member->id;
        }

        foreach ($managers as $i => $manager) {
            $group = Group::firstOrCreate(
                ['owner_id' => $manager->id],
                ['name' => self::GROUP_NAMES[$i], 'permissions' => 0],
            );
            $group->users()->syncWithoutDetaching([$manager->id, ...$buckets[$i]]);
        }
    }

    // ── Licenses ─────────────────────────────────────────────────────────────

    private function createLicenses(array $users): void
    {
        $expiresAt = now()->addMonth();

        foreach ($users as $user) {
            License::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'lemon_key_hash'    => hash('sha256', 'sim-license-' . $user->email),
                    'status'            => 'active',
                    'tier'              => $user->tier,
                    'seats'             => 1,
                    'expires_at'        => $expiresAt,
                    'issued_by_user_id' => null,
                ],
            );
        }
    }

    // ── CLI tokens ───────────────────────────────────────────────────────────

    private function createCliTokens(array $users): array
    {
        $tokens = [];

        foreach ($users as $user) {
            $plain = 'tl_' . bin2hex(random_bytes(16)); // tl_ + 32 hex = 35 chars

            DB::table('cli_tokens')->updateOrInsert(
                ['user_id' => $user->id, 'name' => 'Simulation Token'],
                [
                    'token_hash'   => hash('sha256', $plain),
                    'token_prefix' => substr($plain, 0, 8),
                    'last_used_at' => null,
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ],
            );
            $tokens[$user->email] = $plain;
        }

        return $tokens;
    }

    // ── Groq API key ─────────────────────────────────────────────────────────

    private function readGroqRaw(): ?string
    {
        return DB::table('user_ai_providers')
            ->join('users', 'users.id', '=', 'user_ai_providers.user_id')
            ->where('users.email', 'team-manager@test.local')
            ->where('user_ai_providers.provider', 'groq')
            ->value('user_ai_providers.api_key'); // raw Laravel-encrypted string
    }

    private function copyGroqKey(?string $rawEncrypted, array $users): void
    {
        if ($rawEncrypted === null) {
            $this->command->warn('No Groq key found for team-manager@test.local — skipping AI provider copy.');
            return;
        }

        foreach ($users as $user) {
            DB::table('user_ai_providers')->updateOrInsert(
                ['user_id' => $user->id, 'provider' => 'groq'],
                [
                    'api_key'         => $rawEncrypted, // same cipher, same app key
                    'priority'        => 1,
                    'timeout_seconds' => 5,
                    'enabled'         => true,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ],
            );
        }
    }

    // ── BYOK usage rows (Analytics page) ────────────────────────────────────

    private function createByokRows(array $users): void
    {
        $now  = now();
        $rows = [];

        foreach ($users as $user) {
            for ($i = 0; $i < 5; $i++) {
                $rows[] = [
                    'user_id'     => $user->id,
                    'action'      => self::BYOK_ACTIONS[$i],
                    'ticket_key'  => null,
                    'tokens_used' => random_int(500, 5000),
                    'metadata'    => null, // NULL = BYOK/AI row
                    'created_at'  => $now->copy()->subDays(random_int(1, 30))->subHours(random_int(0, 23))->format('Y-m-d H:i:s'),
                ];
            }
        }

        // Chunk to avoid oversized inserts
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('usage_logs')->insert($chunk);
        }
    }
}
