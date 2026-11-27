<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Validity projection only; caller must independently authorize and scope. */
final class TeacherMonitoringIntegrity {
    public static function attendanceIds() {
        $q=DB::table('presensi as p')->join('jadwal_pelajaran as j','j.id','=','p.jadwal_pelajaran_id')->join('kelas as k','k.id','=','j.kelas_id')->whereColumn('k.tahun_akademik_id','j.tahun_akademik_id')->select('p.id');
        PrincipalAcademicIntegrity::attendance($q,today('Asia/Jakarta')->toDateString());
        return $q;
    }
    public static function gradeIds() {
        $q=DB::table('nilai as n')->join('komponen_nilai as c','c.id','=','n.komponen_nilai_id')->join('jadwal_pelajaran as j','j.id','=','c.jadwal_pelajaran_id')->join('kelas as k','k.id','=','j.kelas_id')->whereColumn('k.tahun_akademik_id','j.tahun_akademik_id')->select('n.id');
        PrincipalAcademicIntegrity::grades($q,today('Asia/Jakarta')->toDateString());
        return $q;
    }
}
