<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_alert_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->string('alert_type', 30);
            $table->string('integration', 30)->default('slack');
            $table->string('target_id', 100);
            $table->string('target_label', 100);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['group_id', 'alert_type', 'integration', 'target_id'], 'car_group_type_int_target_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_alert_rules');
    }
};
