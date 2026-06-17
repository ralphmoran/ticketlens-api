<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DB-level guard: blocks hard DELETE on the platform owner row.
 *
 * Complements the model-layer deleting/forceDeleting events:
 *   - Model events catch Eloquent-path deletes (app code, soft-delete)
 *   - This trigger catches raw-SQL deletes (DB::delete, migrations, direct access)
 *
 * Skipped for SQLite (tests) — the model-layer guard covers all environments.
 * MySQL SIGNAL SQLSTATE '45000' aborts the DELETE and surfaces as a PDOException.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::unprepared('
            CREATE TRIGGER prevent_owner_delete
            BEFORE DELETE ON users
            FOR EACH ROW
            BEGIN
                IF OLD.is_owner IS TRUE THEN
                    SIGNAL SQLSTATE \'45000\'
                        SET MESSAGE_TEXT = \'Cannot delete the platform owner account.\';
                END IF;
            END
        ');
    }

    public function down(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS prevent_owner_delete');
    }
};
