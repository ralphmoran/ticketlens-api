<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ordered pivot: `priority` is the fallback rank for single-output roles
        // (1 = main, 2 = second, 3 = backup, ...). Consensus-type usage ignores
        // order and calls every attached pool row in parallel — see AiProviderRole.
        Schema::create('ai_provider_role_pool', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ai_provider_role_id');
            $table->unsignedBigInteger('ai_provider_pool_id');
            $table->unsignedSmallInteger('priority')->default(1);
            $table->timestamps();

            $table->foreign('ai_provider_role_id', 'aprp_role_fk')->references('id')->on('ai_provider_roles')->cascadeOnDelete();
            $table->foreign('ai_provider_pool_id', 'aprp_pool_fk')->references('id')->on('ai_provider_pools')->cascadeOnDelete();
            $table->unique(['ai_provider_role_id', 'ai_provider_pool_id'], 'aprp_role_pool_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_role_pool');
    }
};
