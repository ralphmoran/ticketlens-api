<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brief_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('slug', 50);
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->json('sections');
            $table->boolean('is_system')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['group_id', 'slug']);
            $table->index('group_id');
            $table->index('is_system');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brief_templates');
    }
};
