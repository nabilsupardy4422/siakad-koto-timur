<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('anggota_kelas', 'tanggal_mulai')) {
            Schema::table('anggota_kelas', function (Blueprint $table) {
                $table->date('tanggal_mulai')
                    ->after('siswa_id');
            });
        }

        if (! Schema::hasColumn('anggota_kelas', 'tanggal_selesai')) {
            Schema::table('anggota_kelas', function (Blueprint $table) {
                $table->date('tanggal_selesai')
                    ->nullable()
                    ->after('tanggal_mulai');
            });
        }

        $indexes = Schema::getIndexes('anggota_kelas');

        $hasOldUnique = collect($indexes)->contains(
            fn (array $index): bool =>
                $index['name'] === 'anggota_kelas_kelas_id_siswa_id_unique'
        );

        if ($hasOldUnique) {
            Schema::table('anggota_kelas', function (Blueprint $table) {
                $table->dropUnique(
                    'anggota_kelas_kelas_id_siswa_id_unique'
                );
            });
        }

        $indexes = Schema::getIndexes('anggota_kelas');

        $hasMembershipUnique = collect($indexes)->contains(
            fn (array $index): bool =>
                $index['name'] === 'anggota_kelas_kelas_siswa_mulai_unique'
        );

        if (! $hasMembershipUnique) {
            Schema::table('anggota_kelas', function (Blueprint $table) {
                $table->unique(
                    ['kelas_id', 'siswa_id', 'tanggal_mulai'],
                    'anggota_kelas_kelas_siswa_mulai_unique'
                );
            });
        }
    }

    public function down(): void
    {
        $indexes = Schema::getIndexes('anggota_kelas');

        $hasMembershipUnique = collect($indexes)->contains(
            fn (array $index): bool =>
                $index['name'] === 'anggota_kelas_kelas_siswa_mulai_unique'
        );

        if ($hasMembershipUnique) {
            Schema::table('anggota_kelas', function (Blueprint $table) {
                $table->dropUnique(
                    'anggota_kelas_kelas_siswa_mulai_unique'
                );
            });
        }

        if (Schema::hasColumn('anggota_kelas', 'tanggal_mulai')) {
            Schema::table('anggota_kelas', function (Blueprint $table) {
                $table->dropColumn([
                    'tanggal_mulai',
                    'tanggal_selesai',
                ]);
            });
        }

        $indexes = Schema::getIndexes('anggota_kelas');

        $hasOldUnique = collect($indexes)->contains(
            fn (array $index): bool =>
                $index['name'] === 'anggota_kelas_kelas_id_siswa_id_unique'
        );

        if (! $hasOldUnique) {
            Schema::table('anggota_kelas', function (Blueprint $table) {
                $table->unique(
                    ['kelas_id', 'siswa_id'],
                    'anggota_kelas_kelas_id_siswa_id_unique'
                );
            });
        }
    }
};