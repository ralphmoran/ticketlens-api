<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usage_logs', function (Blueprint $table) {
            // Plain populated column, not a JSON-extracting generated column:
            // tests run SQLite, prod runs MySQL, and their JSON function syntax
            // differs. Writing the count directly at insert time (PushController)
            // avoids needing any JSON SQL function in the read path ever again.
            $table->unsignedInteger('command_count')->default(0)->after('metadata');
        });

        $this->backfillCommandCounts();
    }

    public function down(): void
    {
        Schema::table('usage_logs', function (Blueprint $table) {
            $table->dropColumn('command_count');
        });
    }

    /**
     * Derive command_count from existing metadata JSON for rows written before
     * this migration. Idempotent — safe to call more than once. Decodes JSON in
     * PHP rather than SQL (see up() note on cross-driver portability).
     *
     * One UPDATE per 500-row chunk (CASE/WHEN batching), not one per row — a
     * per-row UPDATE loop would reintroduce, at deploy time, the same
     * unbounded-cost-on-a-forever-growing-table problem this migration exists
     * to fix at read time.
     */
    public function backfillCommandCounts(): void
    {
        DB::table('usage_logs')
            ->whereNotNull('metadata')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                $caseBindings = [];
                $ids          = [];

                foreach ($rows as $row) {
                    $count           = json_decode($row->metadata, true)['count'] ?? 0;
                    $caseBindings[]  = $row->id;
                    $caseBindings[]  = $count;
                    $ids[]           = $row->id;
                }

                if (empty($ids)) {
                    return;
                }

                $caseSql = implode(' ', array_fill(0, count($ids), 'WHEN ? THEN ?'));
                $idsSql  = implode(',', array_fill(0, count($ids), '?'));

                DB::update(
                    "UPDATE usage_logs SET command_count = CASE id {$caseSql} END WHERE id IN ({$idsSql})",
                    array_merge($caseBindings, $ids)
                );
            });
    }
};
