<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('digest_schedules', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')
                  ->constrained('users')->nullOnDelete();
            $table->index('user_id');

            // Make license_key_hash nullable — new schedules key on user_id instead.
            $table->string('license_key_hash', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('digest_schedules', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropIndex(['user_id']);
            $table->dropColumn('user_id');

            $table->string('license_key_hash', 64)->nullable(false)->change();
        });
    }
};
