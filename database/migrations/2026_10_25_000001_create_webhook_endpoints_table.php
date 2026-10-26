<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            $table->text('secret');                       // encrypted at rest; needed in plaintext to sign
            $table->unsignedSmallInteger('failure_count')->default(0);
            $table->timestamp('last_delivered_at')->nullable();
            $table->timestamp('disabled_at')->nullable(); // set after repeated failed deliveries
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoints');
    }
};
