<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cli_tokens', function (Blueprint $table) {
            // First 8 chars of plaintext token — stored for indexed prefix lookup.
            // Nullable so existing tokens remain valid; populated on next login.
            $table->char('token_prefix', 8)->nullable()->after('token_hash');
            $table->index('token_prefix');
        });
    }

    public function down(): void
    {
        Schema::table('cli_tokens', function (Blueprint $table) {
            $table->dropIndex(['token_prefix']);
            $table->dropColumn('token_prefix');
        });
    }
};
