<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_UNIQUE = 'rapor_siswa_id_tahun_akademik_id_semester_jenis_rapor_unique';
    private const NEW_UNIQUE = 'rapor_siswa_id_tahun_akademik_id_jenis_rapor_unique';
    private const OLD_INDEX = 'rapor_tahun_akademik_id_semester_index';
    private const NEW_INDEX = 'rapor_tahun_akademik_id_index';

    public function up(): void
    {
        if (! Schema::hasColumn('rapor', 'semester')) {
            return;
        }

        if (DB::table('rapor')->select('siswa_id', 'tahun_akademik_id', 'jenis_rapor')
            ->groupBy('siswa_id', 'tahun_akademik_id', 'jenis_rapor')
            ->havingRaw('COUNT(*) > 1')->exists()) {
            throw new \RuntimeException('RAPOR_DUPLICATE_TARGET_KEY_REVIEW_REQUIRED');
        }

        Schema::table('rapor', function (Blueprint $table): void {
            $table->index('tahun_akademik_id', self::NEW_INDEX);
        });
        // Keep a supporting index for siswa_id before removing the old one.
        Schema::table('rapor', function (Blueprint $table): void {
            $table->unique(['siswa_id', 'tahun_akademik_id', 'jenis_rapor'], self::NEW_UNIQUE);
        });
        Schema::table('rapor', function (Blueprint $table): void {
            $table->dropUnique(self::OLD_UNIQUE);
        });
        Schema::table('rapor', function (Blueprint $table): void {
            $table->dropIndex(self::OLD_INDEX);
        });
        Schema::table('rapor', function (Blueprint $table): void {
            $table->dropColumn('semester');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('rapor', 'semester')) {
            return;
        }
        // The original semester values cannot be recovered from this migration.
        if (DB::table('rapor')->exists()) {
            throw new \RuntimeException('RAPOR_ROLLBACK_REQUIRES_DATA_REVIEW');
        }
        Schema::table('rapor', function (Blueprint $table): void {
            $table->string('semester', 20);
        });
        Schema::table('rapor', function (Blueprint $table): void {
            $table->unique(['siswa_id', 'tahun_akademik_id', 'semester', 'jenis_rapor'], self::OLD_UNIQUE);
            $table->index(['tahun_akademik_id', 'semester'], self::OLD_INDEX);
        });
        Schema::table('rapor', function (Blueprint $table): void {
            $table->dropUnique(self::NEW_UNIQUE);
        });
        Schema::table('rapor', function (Blueprint $table): void {
            $table->dropIndex(self::NEW_INDEX);
        });
    }
};
