<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['stale']);
            $table->json('config');        // {stale_days: int, statuses: []}
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['group_id', 'type']);
            $table->index('group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_rules');
    }
};
