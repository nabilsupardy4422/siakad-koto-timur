<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Kolom mungkin sudah terbuat apabila migration sebelumnya
         * gagal setelah tahap ALTER TABLE pertama.
         */
        if (! Schema::hasColumn('wali_kelas', 'tanggal_mulai')) {
            Schema::table('wali_kelas', function (Blueprint $table): void {
                $table->date('tanggal_mulai')
                    ->after('tahun_akademik_id');
            });
        }

        if (! Schema::hasColumn('wali_kelas', 'tanggal_selesai')) {
            Schema::table('wali_kelas', function (Blueprint $table): void {
                $table->date('tanggal_selesai')
                    ->nullable()
                    ->after('tanggal_mulai');
            });
        }

        /*
         * MySQL membutuhkan index pada kolom foreign key.
         * Unique lama (kelas_id, tahun_akademik_id) tidak lagi
         * digunakan karena assignment sekarang mendukung histori.
         *
         * SQLite tidak mendukung dropForeign() berdasarkan nama
         * constraint, sehingga hanya MySQL yang perlu melepas
         * dan membuat ulang foreign key tersebut.
         */
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('wali_kelas', function (Blueprint $table): void {
                $table->dropForeign('wali_kelas_kelas_id_foreign');
                $table->dropUnique(
                    'wali_kelas_kelas_id_tahun_akademik_id_unique'
                );

                $table->foreign('kelas_id')
                    ->references('id')
                    ->on('kelas')
                    ->restrictOnDelete();
            });
        } else {
            /*
             * SQLite tidak memerlukan pelepasan FK untuk menghapus
             * unique index pada kolom foreign key.
             */
            Schema::table('wali_kelas', function (Blueprint $table): void {
                $table->dropUnique(
                    'wali_kelas_kelas_id_tahun_akademik_id_unique'
                );
            });
        }

        /*
         * Hindari pembuatan index ganda apabila migration
         * pernah berjalan sebagian.
         */
        if (
            ! Schema::hasIndex(
                'wali_kelas',
                'wali_kelas_active_assignment_index'
            )
        ) {
            Schema::table('wali_kelas', function (Blueprint $table): void {
                $table->index(
                    [
                        'kelas_id',
                        'tahun_akademik_id',
                        'tanggal_selesai',
                    ],
                    'wali_kelas_active_assignment_index'
                );
            });
        }

        if (
            ! Schema::hasIndex(
                'wali_kelas',
                'wali_kelas_guru_academic_index'
            )
        ) {
            Schema::table('wali_kelas', function (Blueprint $table): void {
                $table->index(
                    [
                        'guru_id',
                        'tahun_akademik_id',
                    ],
                    'wali_kelas_guru_academic_index'
                );
            });
        }
    }

    public function down(): void
    {
        /*
         * Index tambahan dihapus terlebih dahulu.
         */
        if (
            Schema::hasIndex(
                'wali_kelas',
                'wali_kelas_active_assignment_index'
            )
        ) {
            Schema::table('wali_kelas', function (Blueprint $table): void {
                $table->dropIndex('wali_kelas_active_assignment_index');
            });
        }

        if (
            Schema::hasIndex(
                'wali_kelas',
                'wali_kelas_guru_academic_index'
            )
        ) {
            Schema::table('wali_kelas', function (Blueprint $table): void {
                $table->dropIndex('wali_kelas_guru_academic_index');
            });
        }

        /*
         * MySQL perlu melepas FK sebelum unique lama dikembalikan.
         * SQLite tidak mendukung dropForeign() seperti MySQL.
         */
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('wali_kelas', function (Blueprint $table): void {
                $table->dropForeign('wali_kelas_kelas_id_foreign');

                $table->unique(
                    [
                        'kelas_id',
                        'tahun_akademik_id',
                    ],
                    'wali_kelas_kelas_id_tahun_akademik_id_unique'
                );

                $table->foreign('kelas_id')
                    ->references('id')
                    ->on('kelas')
                    ->restrictOnDelete();
            });
        } else {
            Schema::table('wali_kelas', function (Blueprint $table): void {
                $table->unique(
                    [
                        'kelas_id',
                        'tahun_akademik_id',
                    ],
                    'wali_kelas_kelas_id_tahun_akademik_id_unique'
                );
            });
        }

        /*
         * Hapus kolom histori.
         */
        Schema::table('wali_kelas', function (Blueprint $table): void {
            $table->dropColumn([
                'tanggal_mulai',
                'tanggal_selesai',
            ]);
        });
    }
};