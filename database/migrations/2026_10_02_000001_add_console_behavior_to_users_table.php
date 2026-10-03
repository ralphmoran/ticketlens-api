<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // null = follow the server session timing (#51 default)
            $table->unsignedSmallInteger('idle_warning_minutes')->nullable()->after('triage_sort_preference');
            $table->string('session_message_style', 16)->default('playful')->after('idle_warning_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['idle_warning_minutes', 'session_message_style']);
        });
    }
};
