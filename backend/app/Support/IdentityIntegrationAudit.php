<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Read-only diagnostics. Counts are observations, not automatic repair instructions. */
final class IdentityIntegrationAudit
{
    public function counts(): array
    {
        $accounts = DB::table('users as u')->join('roles as r', 'r.id', '=', 'u.role_id')
            ->leftJoin('guru as g', 'g.user_id', '=', 'u.id')
            ->leftJoin('siswa as s', 's.user_id', '=', 'u.id');
        $counts = [
            'accounts_total' => (clone $accounts)->count(),
            'academic_accounts_without_profile' => (clone $accounts)->whereIn('r.code', ['GURU', 'SISWA'])->whereNull('g.id')->whereNull('s.id')->count(),
            'accounts_with_profile_conflict' => (clone $accounts)->where(fn ($q) => $q
                ->where(fn ($g) => $g->whereNotNull('g.id')->where('r.code', '<>', 'GURU'))
                ->orWhere(fn ($s) => $s->whereNotNull('s.id')->where('r.code', '<>', 'SISWA')))->count(),
            'official_name_mismatch' => 0,
            'active_periods' => DB::table('tahun_akademik')->where('is_active', true)->count(),
            'schedule_period_mismatch' => DB::table('jadwal_pelajaran as j')->join('kelas as k', 'k.id', '=', 'j.kelas_id')->whereColumn('j.tahun_akademik_id', '<>', 'k.tahun_akademik_id')->count(),
            'homeroom_period_mismatch' => DB::table('wali_kelas as w')->join('kelas as k', 'k.id', '=', 'w.kelas_id')->whereColumn('w.tahun_akademik_id', '<>', 'k.tahun_akademik_id')->count(),
        ];
        // Strict PHP comparison also detects case-only differences on MySQL's
        // usual case-insensitive collations. Never print names or identifiers.
        (clone $accounts)->select(['u.id as account_id', 'u.name as account_name', 'r.code as role_code', 'g.nama_lengkap as guru_name', 's.nama_lengkap as siswa_name', 'g.id as guru_id', 's.id as siswa_id'])
            ->chunkById(500, function ($rows) use (&$counts): void {
                foreach ($rows as $row) {
                    $official = match (true) {
                        $row->role_code === 'GURU' && $row->guru_id !== null && $row->siswa_id === null => $row->guru_name,
                        $row->role_code === 'SISWA' && $row->siswa_id !== null && $row->guru_id === null => $row->siswa_name,
                        default => null,
                    };
                    if ($official !== null && $official !== $row->account_name) $counts['official_name_mismatch']++;
                }
            }, 'u.id', 'account_id');
        $today = now('Asia/Jakarta')->toDateString();
        $overlaps = DB::table('anggota_kelas as a')->join('kelas as k', 'k.id', '=', 'a.kelas_id')
            ->whereDate('a.tanggal_mulai', '<=', $today)
            ->where(fn ($q) => $q->whereNull('a.tanggal_selesai')->orWhereDate('a.tanggal_selesai', '>=', $today))
            ->select('a.siswa_id', 'k.tahun_akademik_id')->groupBy('a.siswa_id', 'k.tahun_akademik_id')->havingRaw('COUNT(*) > 1');
        $counts['effective_membership_conflicts'] = DB::query()->fromSub($overlaps, 'overlaps')->count();
        $counts['unusable_accounts_with_tokens'] = DB::table('users as u')->where(fn ($q) => $q->where('u.account_status', '<>', 'active')->orWhere('u.requires_activation', true))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('personal_access_tokens as t')->whereColumn('t.tokenable_id', 'u.id')->where('t.tokenable_type', (new User)->getMorphClass()))->count();
        $counts['unneeded_live_activation_codes'] = DB::table('user_activation_tokens as t')->join('users as u', 'u.id', '=', 't.user_id')
            ->whereNull('t.used_at')->where('t.expires_at', '>', now())
            ->where(fn ($q) => $q->where('u.account_status', '<>', 'active')->orWhere('u.requires_activation', false))->count();
        return $counts;
    }
}
