<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('label');
            // Machine-readable kind, separate from the free-text `label` a user can rename
            // freely — code that needs to find "the consensus role" matches on this, never
            // on label text. 'custom' = organizational tag only, no wired behavior yet.
            $table->enum('kind', ['consensus', 'custom'])->default('custom');
            $table->text('generated_prompt')->nullable();
            $table->timestamp('prompt_generated_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_roles');
    }
};
