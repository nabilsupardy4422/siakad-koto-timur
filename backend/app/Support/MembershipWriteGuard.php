<?php
namespace App\Support;
use App\Models\{AnggotaKelas,Siswa};
use Illuminate\Validation\ValidationException;

/** Inside the caller's transaction, use the same student locks as membership mutations. */
final class MembershipWriteGuard {
    public static function lock(array $ids): void {
        Siswa::whereIn('id',$ids)->orderBy('id')->lockForUpdate()->get(['id']);
    }
    public static function effective(int $classId,int $studentId,string $date): bool {
        return AnggotaKelas::where('kelas_id',$classId)->where('siswa_id',$studentId)
            ->whereDate('tanggal_mulai','<=',$date)->where(fn($q)=>$q->whereNull('tanggal_selesai')->orWhereDate('tanggal_selesai','>=',$date))
            ->lockForUpdate()->first(['id'])!==null;
    }
    public static function requireEffective(int $classId,int $studentId,string $date,string $field='siswa_id'): void {
        if(!self::effective($classId,$studentId,$date)) throw ValidationException::withMessages([$field=>'Siswa bukan anggota aktif kelas pada tanggal tersebut. Muat ulang data.']);
    }
}
