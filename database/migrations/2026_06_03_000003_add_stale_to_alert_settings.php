<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_settings', function (Blueprint $table) {
            $table->boolean('stale_enabled')->default(false)->after('compliance_gap_cooldown_hours');
            $table->unsignedSmallInteger('stale_cooldown_hours')->default(4)->after('stale_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('alert_settings', function (Blueprint $table) {
            $table->dropColumn(['stale_enabled', 'stale_cooldown_hours']);
        });
    }
};
