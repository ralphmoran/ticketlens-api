<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // OR-in AttentionQueue (512) for all existing team/enterprise users.
        // Owner rows are skipped — owner is a god-mode singleton, permissions=0 by convention.
        DB::table('users')
            ->whereIn('tier', ['team', 'enterprise'])
            ->where('is_owner', false)
            ->update(['permissions' => DB::raw('permissions | 512')]);
    }

    public function down(): void
    {
        DB::table('users')
            ->whereIn('tier', ['team', 'enterprise'])
            ->where('is_owner', false)
            ->update(['permissions' => DB::raw('permissions & ~512')]);
    }
};
