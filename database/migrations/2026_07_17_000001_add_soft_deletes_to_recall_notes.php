<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recall_notes', function (Blueprint $table) {
            $table->softDeletes();
            $table->index(['group_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('recall_notes', function (Blueprint $table) {
            $table->dropIndex(['group_id', 'deleted_at']);
            $table->dropSoftDeletes();
        });
    }
};
