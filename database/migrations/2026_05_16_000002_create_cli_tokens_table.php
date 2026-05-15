<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cli_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100)->default('CLI Token');
            $table->char('token_hash', 64); // sha256 — plaintext never stored
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('token_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cli_tokens');
    }
};
