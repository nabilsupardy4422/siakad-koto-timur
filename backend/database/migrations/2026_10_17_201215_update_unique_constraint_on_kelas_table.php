<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kelas', function (Blueprint $table) {
            $table->unique(
                ['tahun_akademik_id', 'tingkat', 'nama'],
                'kelas_tahun_tingkat_nama_unique'
            );
        });

        Schema::table('kelas', function (Blueprint $table) {
            $table->dropUnique('kelas_tahun_akademik_id_nama_unique');
        });
    }

    public function down(): void
    {
        Schema::table('kelas', function (Blueprint $table) {
            $table->unique(
                ['tahun_akademik_id', 'nama'],
                'kelas_tahun_akademik_id_nama_unique'
            );
        });

        Schema::table('kelas', function (Blueprint $table) {
            $table->dropUnique('kelas_tahun_tingkat_nama_unique');
        });
    }
};
