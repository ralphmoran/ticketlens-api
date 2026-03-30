<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('digest_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('license_key_hash', 64)->unique(); // sha256 — never raw key
            $table->string('email', 255);
            $table->string('timezone', 50);
            $table->time('deliver_at');
            $table->boolean('active')->default(true);
            $table->timestamp('last_delivered_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('digest_schedules');
    }
};
