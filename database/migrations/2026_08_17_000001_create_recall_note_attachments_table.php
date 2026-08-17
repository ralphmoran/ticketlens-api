<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recall_note_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recall_note_id')->constrained()->cascadeOnDelete();
            $table->string('filename');
            $table->string('disk_path');
            $table->unsignedBigInteger('size_bytes');
            $table->string('mime_type');
            $table->timestamps();

            $table->index('recall_note_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recall_note_attachments');
    }
};
