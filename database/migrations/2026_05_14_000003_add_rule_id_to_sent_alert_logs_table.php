<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sent_alert_logs', function (Blueprint $table) {
            $table->foreignId('rule_id')->nullable()->after('triggered_at')
                ->constrained('custom_alert_rules')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sent_alert_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rule_id');
        });
    }
};
