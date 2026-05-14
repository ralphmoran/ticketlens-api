<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('needs_response_cooldown_hours')->default(4)->after('needs_response_enabled');
            $table->unsignedSmallInteger('aging_cooldown_hours')->default(24)->after('aging_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('alert_settings', function (Blueprint $table) {
            $table->dropColumn(['needs_response_cooldown_hours', 'aging_cooldown_hours']);
        });
    }
};
