<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('triage_snapshots', function (Blueprint $table) {
            $table->json('git_branches')->nullable()->after('tickets');
        });
    }

    public function down(): void
    {
        Schema::table('triage_snapshots', function (Blueprint $table) {
            $table->dropColumn('git_branches');
        });
    }
};
