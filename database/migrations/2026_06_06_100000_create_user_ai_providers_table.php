<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_ai_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('provider', ['groq', 'anthropic', 'openai']);
            $table->text('api_key'); // encrypted at rest via model cast
            $table->unsignedSmallInteger('priority')->default(1);
            $table->unsignedSmallInteger('timeout_seconds')->default(5);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'provider']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_ai_providers');
    }
};
