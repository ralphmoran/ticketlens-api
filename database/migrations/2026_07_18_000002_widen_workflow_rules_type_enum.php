<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widens workflow_rules.type from enum(['stale']) to enum(['stale','custom']).
 *
 * MySQL/MariaDB support ALTER ... MODIFY on an enum directly. SQLite (the test
 * driver, see phpunit.xml) has no ALTER COLUMN — Laravel compiles enum() to a
 * CHECK constraint there, so widening it means rebuilding the table.
 */
return new class extends Migration
{
    private const COLUMNS = ['id', 'group_id', 'type', 'config', 'enabled', 'created_at', 'updated_at'];

    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->rebuildSqliteTable(['stale', 'custom']);
            return;
        }

        DB::statement("ALTER TABLE workflow_rules MODIFY type ENUM('stale','custom') NOT NULL");
    }

    public function down(): void
    {
        if (DB::table('workflow_rules')->where('type', 'custom')->exists()) {
            throw new \RuntimeException(
                'Cannot narrow workflow_rules.type back to enum(\'stale\') — rows with type=\'custom\' exist. '
                . 'Delete them first: WorkflowRule::where(\'type\', \'custom\')->delete();'
            );
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->rebuildSqliteTable(['stale']);
            return;
        }

        DB::statement("ALTER TABLE workflow_rules MODIFY type ENUM('stale') NOT NULL");
    }

    private function rebuildSqliteTable(array $types): void
    {
        Schema::create('workflow_rules_new', function (Blueprint $table) use ($types) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->enum('type', $types);
            $table->json('config');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['group_id', 'type']);
            $table->index('group_id');
        });

        $columns = implode(', ', self::COLUMNS);
        DB::statement("INSERT INTO workflow_rules_new ({$columns}) SELECT {$columns} FROM workflow_rules");

        Schema::drop('workflow_rules');
        Schema::rename('workflow_rules_new', 'workflow_rules');
    }
};
