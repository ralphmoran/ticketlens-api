<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tier_features', function (Blueprint $table): void {
            $table->id();
            $table->string('tier');
            $table->foreignId('feature_id')->constrained('features')->cascadeOnDelete();
            $table->unique(['tier', 'feature_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tier_features');
    }
};
