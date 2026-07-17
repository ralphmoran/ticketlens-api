<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table): void {
            // True only for License rows TeamAccessService creates as a Free/Pro
            // seat-bookkeeping add-on — never on a real purchased/owner-issued
            // license. Lets revenue reporting exclude comped seats from MRR math.
            $table->boolean('granted_by_owner_as_addon')->default(false)->after('seats');
        });
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table): void {
            $table->dropColumn('granted_by_owner_as_addon');
        });
    }
};
