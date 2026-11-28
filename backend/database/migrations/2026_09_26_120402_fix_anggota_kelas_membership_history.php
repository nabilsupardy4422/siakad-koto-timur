<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_UNIQUE = 'anggota_kelas_kelas_id_siswa_id_unique';
    private const NEW_UNIQUE = 'anggota_kelas_kelas_siswa_mulai_unique';

    private function hasIndex(string $name): bool
    {
        return collect(Schema::getIndexes('anggota_kelas'))->contains(
            fn (array $index): bool => $index['name'] === $name
        );
    }

    public function up(): void
    {
        if (! Schema::hasColumn('anggota_kelas', 'tanggal_mulai')) {
            if (DB::table('anggota_kelas')->exists()) {
                throw new \RuntimeException('MEMBERSHIP_START_DATE_REVIEW_REQUIRED');
            }
            Schema::table('anggota_kelas', function (Blueprint $table): void {
                $table->date('tanggal_mulai')->after('siswa_id');
            });
        }
        if (DB::table('anggota_kelas')->whereNull('tanggal_mulai')->exists()) {
            throw new \RuntimeException('MEMBERSHIP_NULL_START_DATE_REVIEW_REQUIRED');
        }
        if (DB::table('anggota_kelas')->select('kelas_id', 'siswa_id', 'tanggal_mulai')
            ->groupBy('kelas_id', 'siswa_id', 'tanggal_mulai')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new \RuntimeException('MEMBERSHIP_DUPLICATE_TARGET_KEY_REVIEW_REQUIRED');
        }
        if (! Schema::hasColumn('anggota_kelas', 'tanggal_selesai')) {
            Schema::table('anggota_kelas', function (Blueprint $table): void {
                $table->date('tanggal_selesai')->nullable()->after('tanggal_mulai');
            });
        }
        // Create replacement index before dropping the supporting old index.
        if (! $this->hasIndex(self::NEW_UNIQUE)) {
            Schema::table('anggota_kelas', function (Blueprint $table): void {
                $table->unique(['kelas_id', 'siswa_id', 'tanggal_mulai'], self::NEW_UNIQUE);
            });
        }
        if ($this->hasIndex(self::OLD_UNIQUE)) {
            Schema::table('anggota_kelas', function (Blueprint $table): void {
                $table->dropUnique(self::OLD_UNIQUE);
            });
        }
    }

    public function down(): void
    {
        if (DB::table('anggota_kelas')->exists()) {
            throw new \RuntimeException('MEMBERSHIP_ROLLBACK_REQUIRES_DATA_REVIEW');
        }
        if (! $this->hasIndex(self::OLD_UNIQUE)) {
            Schema::table('anggota_kelas', function (Blueprint $table): void {
                $table->unique(['kelas_id', 'siswa_id'], self::OLD_UNIQUE);
            });
        }
        if ($this->hasIndex(self::NEW_UNIQUE)) {
            Schema::table('anggota_kelas', function (Blueprint $table): void {
                $table->dropUnique(self::NEW_UNIQUE);
            });
        }
        foreach (['tanggal_selesai', 'tanggal_mulai'] as $column) {
            if (Schema::hasColumn('anggota_kelas', $column)) {
                Schema::table('anggota_kelas', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
