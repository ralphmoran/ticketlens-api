<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('triage_snapshots', function (Blueprint $table) {
            // Make license_key_hash nullable — new pushes key on user_id instead.
            // Drop the old unique constraint (was keyed on license_key_hash + profile).
            $table->dropUnique(['license_key_hash', 'profile']);
            $table->string('license_key_hash', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('triage_snapshots', function (Blueprint $table) {
            $table->string('license_key_hash', 64)->nullable(false)->change();
            $table->unique(['license_key_hash', 'profile']);
        });
    }
};
