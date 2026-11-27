<?php
namespace App\Services;

/** Query helpers for school monitoring only. Aliases are fixed by callers. */
final class PrincipalAcademicIntegrity
{
    public static function attendance($q, string $today): void
    {
        $q->whereDate('p.tanggal','<=',$today)
          ->whereRaw("HEX(UPPER(p.status)) IN ('4841444952','495A494E','53414B4954','414C5041')")
          ->whereNotExists(fn($d)=>$d->selectRaw('1')->from('presensi as dup')->whereColumn('dup.jadwal_pelajaran_id','p.jadwal_pelajaran_id')->whereColumn('dup.siswa_id','p.siswa_id')->whereColumn('dup.id','<>','p.id')->whereRaw('DATE(dup.tanggal) = DATE(p.tanggal)'))
          ->whereExists(fn($m)=>$m->selectRaw('1')->from('anggota_kelas as ak')->whereColumn('ak.kelas_id','j.kelas_id')->whereColumn('ak.siswa_id','p.siswa_id')->whereRaw('DATE(ak.tanggal_mulai) <= DATE(p.tanggal)')->where(fn($e)=>$e->whereNull('ak.tanggal_selesai')->orWhereRaw('DATE(ak.tanggal_selesai) >= DATE(p.tanggal)')));
    }
    public static function grades($q, string $today): void
    {
        $q->whereBetween('n.nilai',[0,100])->whereNotExists(fn($d)=>$d->selectRaw('1')->from('nilai as dup')->whereColumn('dup.komponen_nilai_id','n.komponen_nilai_id')->whereColumn('dup.siswa_id','n.siswa_id')->whereColumn('dup.id','<>','n.id'))
          ->whereExists(fn($m)=>$m->selectRaw('1')->from('anggota_kelas as ak')->whereColumn('ak.kelas_id','j.kelas_id')->whereColumn('ak.siswa_id','n.siswa_id')->whereDate('ak.tanggal_mulai','<=',$today)->where(fn($e)=>$e->whereNull('ak.tanggal_selesai')->orWhereRaw('DATE(ak.tanggal_selesai) >= DATE(ak.tanggal_mulai)')));
    }
}
