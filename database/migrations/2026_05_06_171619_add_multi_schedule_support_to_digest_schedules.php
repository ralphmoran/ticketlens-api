<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('digest_schedules', function (Blueprint $table) {
            $table->dropUnique(['license_key_hash']);
            $table->foreignId('assigned_to_user_id')->nullable()->after('license_key_hash')
                  ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('digest_schedules', function (Blueprint $table) {
            $table->dropForeign(['assigned_to_user_id']);
            $table->dropColumn('assigned_to_user_id');
            $table->unique('license_key_hash');
        });
    }
};
