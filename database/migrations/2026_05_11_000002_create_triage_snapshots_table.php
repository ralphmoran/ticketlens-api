<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('triage_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('license_key_hash', 64)->index();
            $table->string('profile', 100);
            $table->json('tickets');
            $table->unsignedSmallInteger('ticket_count')->default(0);
            $table->timestamp('captured_at');
            $table->timestamps();

            // One snapshot per device+profile — CLI push replaces rather than appends.
            $table->unique(['license_key_hash', 'profile']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('triage_snapshots');
    }
};
