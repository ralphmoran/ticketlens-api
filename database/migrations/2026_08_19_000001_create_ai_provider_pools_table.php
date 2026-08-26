<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_pools', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('group_id');
            $table->unsignedBigInteger('created_by');
            $table->string('title');
            $table->enum('provider_type', ['openai_compatible', 'anthropic']);
            $table->text('api_key'); // encrypted at rest via model cast
            $table->string('endpoint')->nullable(); // required for openai_compatible, unused for anthropic
            $table->string('model');
            $table->text('notes')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->foreign('group_id')->references('id')->on('groups')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->cascadeOnDelete();
            $table->index('group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_pools');
    }
};
