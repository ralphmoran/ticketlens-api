<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('tier')->default('free')->after('email');
            $table->unsignedInteger('permissions')->default(64)->after('tier'); // 64 = MultiProject (Free)
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['tier', 'permissions']);
        });
    }
};
