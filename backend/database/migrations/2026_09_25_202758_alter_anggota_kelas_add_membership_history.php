<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('anggota_kelas', function (Blueprint $table) {
            $table->unique(
                ['kelas_id', 'siswa_id', 'tanggal_mulai'],
                'anggota_kelas_kelas_siswa_mulai_unique'
            );

            $table->foreign('kelas_id')
                ->references('id')
                ->on('kelas')
                ->restrictOnDelete();

            $table->foreign('siswa_id')
                ->references('id')
                ->on('siswa')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('anggota_kelas', function (Blueprint $table) {
            $table->dropForeign(['kelas_id']);
            $table->dropForeign(['siswa_id']);

            $table->dropUnique('anggota_kelas_kelas_siswa_mulai_unique');

            $table->unique(
                ['kelas_id', 'siswa_id'],
                'anggota_kelas_kelas_id_siswa_id_unique'
            );

            $table->foreign('kelas_id')
                ->references('id')
                ->on('kelas')
                ->restrictOnDelete();

            $table->foreign('siswa_id')
                ->references('id')
                ->on('siswa')
                ->restrictOnDelete();
        });
    }
};