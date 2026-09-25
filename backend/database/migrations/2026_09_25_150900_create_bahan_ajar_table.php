<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bahan_ajar', function (Blueprint $table) {
            $table->id();

            $table->foreignId('jadwal_pelajaran_id')
                ->constrained('jadwal_pelajaran')
                ->restrictOnDelete();

            $table->string('judul');

            $table->text('deskripsi')->nullable();

            $table->string('file_path')->nullable();

            $table->string('file_name')->nullable();

            $table->string('file_mime_type', 100)->nullable();

            $table->unsignedBigInteger('file_size')->nullable();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            $table->index([
                'jadwal_pelajaran_id',
                'created_by',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bahan_ajar');
    }
};