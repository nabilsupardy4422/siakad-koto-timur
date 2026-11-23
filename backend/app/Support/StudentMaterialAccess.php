<?php

namespace App\Support;

use App\Models\BahanAjar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Read-only student entitlement for learning materials.
 * A material is accessible only while the student's membership was effective
 * on its creation date (inclusive start/end) and has already started today.
 * Shared by list, direct detail, and all file download paths.
 */
final class StudentMaterialAccess
{
    public static function apply(Builder $query, int $studentId): void
    {
        $today = Carbon::today('Asia/Jakarta')->toDateString();
        $query->whereHas('jadwalPelajaran.kelas.anggotaKelas',
            function ($member) use ($studentId, $today): void {
                $member->where('siswa_id', $studentId)
                    ->whereNotNull('tanggal_mulai')
                    ->whereDate('tanggal_mulai', '<=', $today)
                    ->whereRaw('DATE(bahan_ajar.created_at) >= DATE(anggota_kelas.tanggal_mulai)')
                    ->where(function ($active): void {
                        $active->whereNull('tanggal_selesai')
                            ->orWhereRaw('DATE(bahan_ajar.created_at) <= DATE(anggota_kelas.tanggal_selesai)');
                    });
            }
        );
    }

    public static function allows(int $studentId, BahanAjar $material): bool
    {
        $query = BahanAjar::query()->whereKey($material->id);
        self::apply($query, $studentId);
        return $query->exists();
    }
}
