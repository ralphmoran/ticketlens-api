<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('triage_snapshots', function (Blueprint $table) {
            $table->index(['user_id', 'captured_at'], 'triage_snapshots_user_captured_idx');
        });
    }

    public function down(): void
    {
        Schema::table('triage_snapshots', function (Blueprint $table) {
            $table->dropIndex('triage_snapshots_user_captured_idx');
        });
    }
};
