<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('usage_logs', function (Blueprint $table) {
            $table->unsignedInteger('tokens_used')->default(0)->change();
            $table->index(['user_id', 'created_at'], 'usage_logs_user_created_idx');
            $table->index(['action', 'created_at'], 'usage_logs_action_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('usage_logs', function (Blueprint $table) {
            $table->dropIndex('usage_logs_user_created_idx');
            $table->dropIndex('usage_logs_action_created_idx');
            $table->unsignedSmallInteger('tokens_used')->default(0)->change();
        });
    }
};
