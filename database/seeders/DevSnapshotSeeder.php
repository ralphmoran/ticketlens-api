<?php

namespace Database\Seeders;

use App\Models\TriageSnapshot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds 35 days of triage_snapshots and usage_logs for dev test accounts.
 * Requires DevSeeder to have run first (users + group must exist).
 *
 * Run: php artisan db:seed --class=DevSnapshotSeeder
 * Idempotent — truncates before seeding.
 */
class DevSnapshotSeeder extends Seeder
{
    private const PROFILES = ['production', 'staging'];

    public function run(): void
    {
        DB::table('triage_snapshots')->truncate();
        DB::table('usage_logs')->truncate();

        $users = User::whereIn('email', [
            'free@test.local',
            'pro@test.local',
            'team-member@test.local',
            'team-manager@test.local',
        ])->get()->keyBy('email');

        foreach ($users as $email => $user) {
            $this->seedUser($user, $email);
        }

        $this->command->info('DevSnapshotSeeder: snapshots and usage_logs seeded for all test accounts.');
    }

    private function seedUser(User $user, string $email): void
    {
        $workdays = $this->workdaysBack(35);

        foreach ($workdays as $day) {
            $profile = self::PROFILES[array_rand(self::PROFILES)];
            $hour    = rand(8, 18);
            $capturedAt = Carbon::parse($day)->setHour($hour)->setMinute(rand(0, 59));

            $tickets = $this->fakeTickets($email, $capturedAt);

            TriageSnapshot::create([
                'user_id'      => $user->id,
                'profile'      => $profile,
                'tickets'      => $tickets,
                'ticket_count' => count($tickets),
                'captured_at'  => $capturedAt,
            ]);

            // One usage_log entry per push
            DB::table('usage_logs')->insert([
                'user_id'    => $user->id,
                'action'     => 'triage_push',
                'ticket_key' => null,
                'tokens_used' => rand(200, 800),
                'created_at'  => $capturedAt,
            ]);
        }
    }

    private function fakeTickets(string $email, Carbon $date): array
    {
        $count = rand(3, 12);
        $flags = [[], [], ['needs-response'], ['aging'], ['stale']];

        return array_map(fn ($i) => [
            'key'                 => 'PROJ-' . ($i + 100),
            'summary'             => "Sample ticket {$i} for {$email}",
            'status'              => 'In Progress',
            'assignee'            => 'Dev',
            'attention_score'     => round(rand(10, 90) / 10, 1),
            'flags'               => $flags[array_rand($flags)],
            'last_comment_at'     => $date->copy()->subHours(rand(1, 72))->toIso8601String(),
            'compliance_coverage' => null,
            'compliance_status'   => 'unknown',
            'url'                 => "https://jira.example.com/browse/PROJ-{$i}",
            'last_updated'        => $date->toIso8601String(),
        ], range(0, $count - 1));
    }

    private function workdaysBack(int $days): array
    {
        $result = [];
        $day    = Carbon::now()->subDay();

        while (count($result) < $days) {
            // Include weekdays, and ~60% of weekends
            if ($day->isWeekday() || rand(1, 10) <= 3) {
                $result[] = $day->toDateString();
            }
            $day = $day->subDay();
        }

        return $result;
    }
}
