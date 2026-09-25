<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('rapor', 'semester')) {
            return;
        }

        Schema::table('rapor', function (Blueprint $table): void {
            $table->index(
                'tahun_akademik_id',
                'rapor_tahun_akademik_id_index'
            );
        });

        Schema::table('rapor', function (Blueprint $table): void {
            $table->dropUnique(
                'rapor_siswa_id_tahun_akademik_id_semester_jenis_rapor_unique'
            );
        });

        Schema::table('rapor', function (Blueprint $table): void {
            $table->dropIndex(
                'rapor_tahun_akademik_id_semester_index'
            );
        });

        Schema::table('rapor', function (Blueprint $table): void {
            $table->dropColumn('semester');
        });

        Schema::table('rapor', function (Blueprint $table): void {
            $table->unique(
                [
                    'siswa_id',
                    'tahun_akademik_id',
                    'jenis_rapor',
                ],
                'rapor_siswa_id_tahun_akademik_id_jenis_rapor_unique'
            );
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('rapor', 'semester')) {
            return;
        }

        Schema::table('rapor', function (Blueprint $table): void {
            $table->string('semester', 20)->nullable();
        });

        Schema::table('rapor', function (Blueprint $table): void {
            $table->dropUnique(
                'rapor_siswa_id_tahun_akademik_id_jenis_rapor_unique'
            );
        });

        Schema::table('rapor', function (Blueprint $table): void {
            $table->unique(
                [
                    'siswa_id',
                    'tahun_akademik_id',
                    'semester',
                    'jenis_rapor',
                ],
                'rapor_siswa_id_tahun_akademik_id_semester_jenis_rapor_unique'
            );

            $table->index(
                [
                    'tahun_akademik_id',
                    'semester',
                ],
                'rapor_tahun_akademik_id_semester_index'
            );
        });

        Schema::table('rapor', function (Blueprint $table): void {
            $table->dropIndex(
                'rapor_tahun_akademik_id_index'
            );
        });
    }
};