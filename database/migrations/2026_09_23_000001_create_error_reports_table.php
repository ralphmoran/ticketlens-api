<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('error_reports', function (Blueprint $table): void {
            $table->id();
            // No user/group link, by design (49e) — reports are anonymous,
            // opt-in diagnostics; nothing here to GDPR-delete or leak.
            $table->string('cli_version', 32);
            $table->string('os', 64)->nullable();
            $table->string('command', 255)->nullable();
            $table->text('message');
            $table->text('stack_trace')->nullable();
            $table->string('profile_tier', 32)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            // Intentionally no updated_at — append-only, same as audit_logs
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_reports');
    }
};
