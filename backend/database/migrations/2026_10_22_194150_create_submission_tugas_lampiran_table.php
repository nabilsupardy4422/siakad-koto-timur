<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_tugas_lampiran', function (Blueprint $table) {
            $table->id();

            $table->foreignId('submission_tugas_id')
                ->constrained('submission_tugas')
                ->cascadeOnDelete();

            $table->string('file_path');
            $table->string('file_name');
            $table->string('file_mime_type', 100);
            $table->unsignedBigInteger('file_size');

            $table->timestamps();

            $table->index('submission_tugas_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_tugas_lampiran');
    }
};
