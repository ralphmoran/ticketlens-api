<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('invited_at')->nullable()->after('avatar_path');
            $table->timestamp('activated_at')->nullable()->after('invited_at');
        });

        // Every user that already exists predates the invite/activation flow —
        // treat them as activated on creation so they never show as a pending
        // invite. invited_at stays null for them; only MembersService::invite()
        // sets it going forward, for genuinely new invitees.
        DB::table('users')->update(['activated_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['invited_at', 'activated_at']);
        });
    }
};
