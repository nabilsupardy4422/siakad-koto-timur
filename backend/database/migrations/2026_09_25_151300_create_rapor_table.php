<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rapor', function (Blueprint $table) {
            $table->id();

            $table->foreignId('siswa_id')
                ->constrained('siswa')
                ->restrictOnDelete();

            $table->foreignId('tahun_akademik_id')
                ->constrained('tahun_akademik')
                ->restrictOnDelete();

            $table->string('semester', 20);

            $table->string('jenis_rapor', 30);

            $table->string('file_path');

            $table->string('file_name');

            $table->string('file_mime_type', 100);

            $table->unsignedBigInteger('file_size');

            $table->foreignId('uploaded_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('uploaded_at');

            $table->timestamps();

            $table->unique([
                'siswa_id',
                'tahun_akademik_id',
                'semester',
                'jenis_rapor',
            ]);

            $table->index([
                'tahun_akademik_id',
                'semester',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rapor');
    }
};