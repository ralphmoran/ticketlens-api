<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slack_digest_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->tinyInteger('day_of_week'); // 0=Sunday … 6=Saturday (Carbon convention)
            $table->string('deliver_at', 5);    // HH:MM
            $table->string('timezone');
            $table->enum('target_type', ['channel', 'user']);
            $table->string('target_id');
            $table->string('target_label');
            $table->boolean('active')->default(true);
            $table->timestamp('last_delivered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slack_digest_schedules');
    }
};
