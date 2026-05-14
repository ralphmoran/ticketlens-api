<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sent_alert_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->string('alert_type', 30);
            $table->string('ticket_key', 30);
            $table->timestamp('triggered_at');
            $table->index(['group_id', 'alert_type', 'ticket_key', 'triggered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_alert_logs');
    }
};
