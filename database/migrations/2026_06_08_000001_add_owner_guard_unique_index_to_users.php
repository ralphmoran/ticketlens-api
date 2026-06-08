<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M6 — Enforce at most one is_owner=true row in users.
 *
 * MySQL does not support partial (filtered) unique indexes, so we use a STORED
 * generated column that is 1 for is_owner=true and NULL otherwise. Unique indexes
 * in MySQL skip NULL values, so only one non-NULL row is ever allowed.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Generated columns with IF() are MySQL/MariaDB-specific.
        // SQLite (used in tests) and other drivers are skipped — the app-level
        // singleton guard in User::saving() covers all environments.
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement('
            ALTER TABLE users
            ADD COLUMN owner_guard TINYINT(1) GENERATED ALWAYS AS (IF(is_owner = 1, 1, NULL)) STORED
        ');

        Schema::table('users', function (Blueprint $table) {
            $table->unique('owner_guard', 'users_owner_guard_unique');
        });
    }

    public function down(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_owner_guard_unique');
            $table->dropColumn('owner_guard');
        });
    }
};
