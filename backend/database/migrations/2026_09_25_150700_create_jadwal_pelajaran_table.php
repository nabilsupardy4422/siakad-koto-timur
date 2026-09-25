<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jadwal_pelajaran', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tahun_akademik_id')
                ->constrained('tahun_akademik')
                ->restrictOnDelete();

            $table->foreignId('kelas_id')
                ->constrained('kelas')
                ->restrictOnDelete();

            $table->foreignId('mapel_id')
                ->constrained('mapel')
                ->restrictOnDelete();

            $table->foreignId('guru_id')
                ->constrained('guru')
                ->restrictOnDelete();

            $table->string('semester', 20);

            $table->string('hari', 20);

            $table->time('jam_mulai');

            $table->time('jam_selesai');

            $table->timestamps();

            $table->index([
                'tahun_akademik_id',
                'kelas_id',
            ]);

            $table->index([
                'tahun_akademik_id',
                'guru_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jadwal_pelajaran');
    }
};