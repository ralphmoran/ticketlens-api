<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('triage_snapshots', function (Blueprint $table): void {
            $table->json('cli_activity')->nullable()->after('ticket_count');
        });
    }

    public function down(): void
    {
        Schema::table('triage_snapshots', function (Blueprint $table): void {
            $table->dropColumn('cli_activity');
        });
    }
};
