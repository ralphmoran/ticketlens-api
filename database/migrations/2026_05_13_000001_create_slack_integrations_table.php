<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slack_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->foreignId('connected_by')->constrained('users')->cascadeOnDelete();
            $table->string('workspace_id');
            $table->string('workspace_name');
            $table->text('bot_token'); // encrypted via model cast
            $table->string('channel_id')->nullable();
            $table->string('channel_name')->nullable();
            $table->timestamps();

            $table->unique('group_id'); // one integration per team
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slack_integrations');
    }
};
