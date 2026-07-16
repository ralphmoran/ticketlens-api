<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recall_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('tracker_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id');
            $table->string('title');
            $table->json('aliases');
            $table->json('tickets');
            $table->json('tags');
            $table->json('sources');
            $table->longText('body');
            $table->enum('status', ['unverified', 'verified'])->default('unverified');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['group_id', 'external_id']);
            $table->index(['group_id', 'status']);
            $table->index(['group_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recall_notes');
    }
};
