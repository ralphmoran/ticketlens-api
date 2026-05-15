<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_settings', function (Blueprint $table) {
            $table->boolean('compliance_gap_enabled')->default(false)->after('aging_cooldown_hours');
            $table->unsignedSmallInteger('compliance_gap_cooldown_hours')->default(24)->after('compliance_gap_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('alert_settings', function (Blueprint $table) {
            $table->dropColumn(['compliance_gap_enabled', 'compliance_gap_cooldown_hours']);
        });
    }
};
