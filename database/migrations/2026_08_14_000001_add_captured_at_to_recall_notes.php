<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recall_notes', function (Blueprint $table) {
            // Client-supplied local-creation instant (`note add` time), distinct
            // from `created_at`/`published_at` which reflect when the row was
            // first/last received by this server. Nullable: notes pushed by a
            // CLI version predating this column never sent it and have none.
            $table->timestamp('captured_at')->nullable()->after('published_at');
        });
    }

    public function down(): void
    {
        Schema::table('recall_notes', function (Blueprint $table) {
            $table->dropColumn('captured_at');
        });
    }
};
