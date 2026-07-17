<?php

namespace Database\Seeders;

use App\Models\Feature;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the features table and default tier→feature mappings.
 *
 * Bit values match app/Enums/Permission.php exactly.
 * Tier presets: free=64, pro=2119 (64|4|2|1|2048), team=6783 (2687|4096 Recall).
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
        ['name' => 'team_manage_seats',   'bit_value' => 256,  'label' => 'Team: Manage Seats',   'description' => 'Allocate and rotate team seats',                        'sort_order' => 90],
        ['name' => 'workflow_rules',    'bit_value' => 2048, 'label' => 'Workflow Rules',          'description' => 'Stale status detection and workflow automation rules',  'sort_order' => 75],
        ['name' => 'attention_queue',   'bit_value' => 512,  'label' => 'Attention Queue',         'description' => 'Team dev attention queue in Console',                   'sort_order' => 85],
        ['name' => 'team_view_health',  'bit_value' => 1024, 'label' => 'Team: View Health',       'description' => 'Team lead health dashboard (manager-assigned, not a tier preset)', 'sort_order' => 95],
        ['name' => 'recall',            'bit_value' => 4096, 'label' => 'Recall',                  'description' => 'Team knowledge notes — captured, synced, and surfaced back into ticket briefs (owner-assigned per tier or per client, not a tier preset default)', 'sort_order' => 100],
    ];

    // Default tier→feature preset (mirrors Permission enum presets)
    // free=64, pro=2119 (64|4|2|1|2048), team=enterprise=6783 (pro|8|16|32|512|4096)
    // Note: bit 1024 (TeamViewHealth) is manager-assigned, not in any tier preset.
    private const TIER_PRESETS = [
        'free' => [64],
        'pro'  => [64, 4, 2, 1, 2048],
        'team' => [64, 32, 16, 8, 4, 2, 1, 512, 2048, 4096],
        'enterprise' => [64, 32, 16, 8, 4, 2, 1, 512, 2048, 4096],
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

        $this->command?->info('  Features seeded: ' . count(self::FEATURES));

        // Build tier_features from presets — insertOrIgnore is idempotent and safe to re-run.
        // Do NOT truncate here: the Owner Panel allows per-tier customisation that must be preserved.
        $featuresByBit = Feature::all()->keyBy('bit_value');

        foreach (self::TIER_PRESETS as $tier => $bits) {
            foreach ($bits as $bit) {
                $feature = $featuresByBit->get($bit);
                if ($feature) {
                    DB::table('tier_features')->insertOrIgnore([
                        'tier'       => $tier,
                        'feature_id' => $feature->id,
                    ]);
                }
            }
        }

        $this->command?->info('  Tier→feature mappings seeded.');
    }
}
