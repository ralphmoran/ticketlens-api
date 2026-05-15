<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracker_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);           // e.g. "work", "acme"
            $table->enum('tracker_type', ['jira', 'github']);
            $table->string('base_url', 500);
            $table->string('auth_method', 20);     // cloud, pat, basic, github
            $table->string('email', 255)->nullable();
            $table->json('ticket_prefixes')->nullable();
            $table->json('project_paths')->nullable();
            $table->json('triage_statuses')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracker_profiles');
    }
};
