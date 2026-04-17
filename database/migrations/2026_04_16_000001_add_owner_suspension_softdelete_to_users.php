<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_owner')->default(false)->after('permissions');
            $table->timestamp('suspended_at')->nullable()->after('is_owner');
            $table->softDeletes()->after('suspended_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['is_owner', 'suspended_at', 'deleted_at']);
        });
    }
};
