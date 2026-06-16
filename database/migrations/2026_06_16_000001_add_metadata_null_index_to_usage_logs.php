<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usage_logs', function (Blueprint $table) {
            // MySQL cannot index a JSON column directly.
            // A stored generated column lets us index IS NOT NULL efficiently.
            $table->tinyInteger('has_metadata')
                ->storedAs('(metadata IS NOT NULL)')
                ->after('metadata');
            $table->index('has_metadata', 'usage_logs_has_metadata_idx');
        });
    }

    public function down(): void
    {
        Schema::table('usage_logs', function (Blueprint $table) {
            $table->dropIndex('usage_logs_has_metadata_idx');
            $table->dropColumn('has_metadata');
        });
    }
};
