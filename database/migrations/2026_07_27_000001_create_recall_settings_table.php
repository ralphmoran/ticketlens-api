<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recall_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('group_id')->unique();
            $table->unsignedInteger('flush_cooldown_ms');
            $table->unsignedInteger('timeout_ms');
            $table->unsignedInteger('max_queue_size');
            $table->unsignedBigInteger('max_entry_age_ms');
            $table->timestamps();

            $table->foreign('group_id')->references('id')->on('groups')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recall_settings');
    }
};
