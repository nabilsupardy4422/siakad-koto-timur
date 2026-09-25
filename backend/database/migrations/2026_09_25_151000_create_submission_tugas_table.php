<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_tugas', function (Blueprint $table) {
            $table->id();

            $table->foreignId('bahan_ajar_id')
                ->constrained('bahan_ajar')
                ->restrictOnDelete();

            $table->foreignId('siswa_id')
                ->constrained('siswa')
                ->restrictOnDelete();

            $table->string('file_path')->nullable();

            $table->string('file_name')->nullable();

            $table->string('file_mime_type', 100)->nullable();

            $table->unsignedBigInteger('file_size')->nullable();

            $table->timestamp('submitted_at')->nullable();

            $table->string('status', 20)
                ->default('submitted');

            $table->text('catatan')->nullable();

            $table->timestamps();

            $table->unique([
                'bahan_ajar_id',
                'siswa_id',
            ]);

            $table->index([
                'siswa_id',
                'submitted_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_tugas');
    }
};