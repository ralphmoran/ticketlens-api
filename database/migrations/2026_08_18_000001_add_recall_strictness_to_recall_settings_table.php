<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recall_settings', function (Blueprint $table) {
            $table->string('recall_strictness')->nullable()->after('max_entry_age_ms');
        });
    }

    public function down(): void
    {
        Schema::table('recall_settings', function (Blueprint $table) {
            $table->dropColumn('recall_strictness');
        });
    }
};
