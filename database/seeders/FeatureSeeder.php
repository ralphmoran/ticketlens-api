<?php

namespace Database\Seeders;

use App\Models\Feature;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the features table and default tier→feature mappings.
 *
 * Bit values match app/Enums/Permission.php exactly.
 * Tier presets: free=64, pro=71, team=127.
 *
 * Run: php artisan db:seed --class=FeatureSeeder
 */
class FeatureSeeder extends Seeder
{
    private const FEATURES = [
        ['name' => 'schedules',        'bit_value' => 1,   'label' => 'Schedules',         'description' => 'Automated ticket fetch schedules', 'sort_order' => 10],
        ['name' => 'digests',          'bit_value' => 2,   'label' => 'Digests',            'description' => 'Email digest delivery',            'sort_order' => 20],
        ['name' => 'summarize',        'bit_value' => 4,   'label' => 'Summarize',          'description' => 'AI ticket summarization',          'sort_order' => 30],
        ['name' => 'compliance',       'bit_value' => 8,   'label' => 'Compliance',         'description' => 'Compliance export and audit',      'sort_order' => 40],
        ['name' => 'export',           'bit_value' => 16,  'label' => 'Export',             'description' => 'CSV / JSON export',                'sort_order' => 50],
        ['name' => 'multi_account',    'bit_value' => 32,  'label' => 'Multi-Account',      'description' => 'Team seat management',             'sort_order' => 60],
        ['name' => 'savings_analytics','bit_value' => 64,  'label' => 'Savings Analytics',  'description' => 'Token savings dashboard',          'sort_order' => 70],
        ['name' => 'team_manage_members', 'bit_value' => 128, 'label' => 'Team: Manage Members', 'description' => 'Invite and remove team members', 'sort_order' => 80],
        ['name' => 'team_manage_seats',   'bit_value' => 256, 'label' => 'Team: Manage Seats',   'description' => 'Allocate and rotate team seats', 'sort_order' => 90],
    ];

    // Default tier→feature preset (mirrors Permission enum presets)
    // free=64, pro=71 (64|4|2|1), team=127 (64|32|16|8|4|2|1)
    private const TIER_PRESETS = [
        'free' => [64],
        'pro'  => [64, 4, 2, 1],
        'team' => [64, 32, 16, 8, 4, 2, 1],
        'enterprise' => [], // placeholder — owner configures
    ];

    public function run(): void
    {
        // Upsert features
        foreach (self::FEATURES as $data) {
            Feature::updateOrCreate(
                ['name' => $data['name']],
                $data,
            );
        }

        $this->command->info('  Features seeded: ' . count(self::FEATURES));

        // Build tier_features from presets
        $featuresByBit = Feature::all()->keyBy('bit_value');

        DB::table('tier_features')->truncate();

        foreach (self::TIER_PRESETS as $tier => $bits) {
            foreach ($bits as $bit) {
                $feature = $featuresByBit->get($bit);
                if ($feature) {
                    DB::table('tier_features')->insert([
                        'tier'       => $tier,
                        'feature_id' => $feature->id,
                    ]);
                }
            }
        }

        $this->command->info('  Tier→feature mappings seeded.');
    }
}
