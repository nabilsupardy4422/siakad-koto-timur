<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presensi', function (Blueprint $table) {
            $table->id();

            $table->foreignId('jadwal_pelajaran_id')
                ->constrained('jadwal_pelajaran')
                ->restrictOnDelete();

            $table->foreignId('siswa_id')
                ->constrained('siswa')
                ->restrictOnDelete();

            $table->date('tanggal');

            $table->string('status', 20);

            $table->text('catatan')->nullable();

            $table->timestamps();

            $table->unique([
                'jadwal_pelajaran_id',
                'siswa_id',
                'tanggal',
            ]);

            $table->index([
                'siswa_id',
                'tanggal',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presensi');
    }
};