<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracker_profiles', function (Blueprint $table) {
            $table->json('stale_rule')->nullable()->after('triage_statuses');
            $table->json('known_statuses')->nullable()->after('stale_rule');
            $table->timestamp('statuses_cached_at')->nullable()->after('known_statuses');
        });
    }

    public function down(): void
    {
        Schema::table('tracker_profiles', function (Blueprint $table) {
            $table->dropColumn(['stale_rule', 'known_statuses', 'statuses_cached_at']);
        });
    }
};
