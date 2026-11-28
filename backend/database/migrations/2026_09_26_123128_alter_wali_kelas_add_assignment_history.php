<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD = 'wali_kelas_kelas_id_tahun_akademik_id_unique';
    private const ACTIVE = 'wali_kelas_active_assignment_index';
    private const GURU = 'wali_kelas_guru_academic_index';

    public function up(): void
    {
        if (! Schema::hasColumn('wali_kelas', 'tanggal_mulai')) {
            if (DB::table('wali_kelas')->exists()) {
                throw new RuntimeException('WALI_START_DATE_REVIEW_REQUIRED');
            }
            Schema::table('wali_kelas', function (Blueprint $table): void {
                $table->date('tanggal_mulai')->after('tahun_akademik_id');
            });
        }
        if (DB::table('wali_kelas')->whereNull('tanggal_mulai')->exists()) {
            throw new RuntimeException('WALI_NULL_START_DATE_REVIEW_REQUIRED');
        }
        if (! Schema::hasColumn('wali_kelas', 'tanggal_selesai')) {
            Schema::table('wali_kelas', function (Blueprint $table): void {
                $table->date('tanggal_selesai')->nullable()->after('tanggal_mulai');
            });
        }
        // Keep the original FK; add a supporting index before dropping the old one.
        if (! Schema::hasIndex('wali_kelas', self::ACTIVE)) {
            Schema::table('wali_kelas', function (Blueprint $table): void {
                $table->index(['kelas_id', 'tahun_akademik_id', 'tanggal_selesai'], self::ACTIVE);
            });
        }
        if (! Schema::hasIndex('wali_kelas', self::GURU)) {
            Schema::table('wali_kelas', function (Blueprint $table): void {
                $table->index(['guru_id', 'tahun_akademik_id'], self::GURU);
            });
        }
        if (Schema::hasIndex('wali_kelas', self::OLD)) {
            Schema::table('wali_kelas', function (Blueprint $table): void {
                $table->dropUnique(self::OLD);
            });
        }
    }

    public function down(): void
    {
        // Historical dates cannot safely be reconstructed after rollback.
        if (DB::table('wali_kelas')->exists()) {
            throw new RuntimeException('WALI_ROLLBACK_REQUIRES_DATA_REVIEW');
        }
        if (! Schema::hasIndex('wali_kelas', self::OLD)) {
            Schema::table('wali_kelas', function (Blueprint $table): void {
                $table->unique(['kelas_id', 'tahun_akademik_id'], self::OLD);
            });
        }
        if (Schema::hasIndex('wali_kelas', self::ACTIVE)) {
            Schema::table('wali_kelas', function (Blueprint $table): void {
                $table->dropIndex(self::ACTIVE);
            });
        }
        if (Schema::hasIndex('wali_kelas', self::GURU)) {
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $support = collect(Schema::getIndexes('wali_kelas'))->contains(
                    fn (array $index): bool => $index['name'] !== self::GURU
                        && ($index['columns'][0] ?? null) === 'guru_id'
                );
                if (! $support) {
                    Schema::table('wali_kelas', function (Blueprint $table): void {
                        $table->index('guru_id', 'wali_kelas_guru_id_foreign');
                    });
                }
            }
            Schema::table('wali_kelas', function (Blueprint $table): void {
                $table->dropIndex(self::GURU);
            });
        }
        Schema::table('wali_kelas', function (Blueprint $table): void {
            $table->dropColumn(['tanggal_mulai', 'tanggal_selesai']);
        });
    }
};
