<?php

namespace App\Services;

use App\Models\AnggotaKelas;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\AcademicMembershipEvent;
use App\Models\Presensi;
use App\Models\SubmissionTugas;
use Illuminate\Support\Facades\DB;
use App\Support\MembershipInterval;

/** All administrative membership writes serialize on the class and student rows. */
final class ClassMembershipService
{
    public const LIMIT = 100;

    public function snapshot(Kelas $class, array $ids, string $start, bool $lock = false): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);
        $classQuery = Kelas::query();
        if ($lock) $classQuery->lockForUpdate();
        $class = $classQuery->findOrFail($class->id);
        $students = Siswa::whereIn('id', $ids)->orderBy('id');
        if ($lock) $students->lockForUpdate();
        $students = $students->get()->keyBy('id');
        $members = AnggotaKelas::whereIn('siswa_id', $ids)
            ->whereHas('kelas', fn ($q) => $q->where('tahun_akademik_id', $class->tahun_akademik_id))
            ->orderBy('id');
        if ($lock) $members->lockForUpdate();
        $members = $members->get()->groupBy('siswa_id');
        $events = AcademicMembershipEvent::whereIn('siswa_id', $ids)->whereIn('action', ['graduate','withdraw'])->orderBy('id');
        if ($lock) $events->lockForUpdate();
        $terminal = $events->get()->groupBy('siswa_id');
        $classNames = Kelas::whereIn('id', $members->flatten(1)->pluck('kelas_id')->unique())->get()->keyBy('id');
        $rows = []; $state = [];
        foreach ($ids as $id) {
            $student = $students->get($id);
            $history = $members->get($id, collect());
            $conflicts = $history->filter(fn ($m) => MembershipInterval::conflictsWithOpen($m->tanggal_mulai?->toDateString(), $m->tanggal_selesai?->toDateString(), $start));
            $closed = $terminal->get($id, collect())->isNotEmpty();
            $details = $conflicts->map(fn ($m) => ['kelas_id'=>$m->kelas_id,'nama'=>$classNames->get($m->kelas_id)?->nama,'tingkat'=>$classNames->get($m->kelas_id)?->tingkat,'tanggal_mulai'=>$m->tanggal_mulai?->toDateString(),'tanggal_selesai'=>$m->tanggal_selesai?->toDateString()])->values()->all();
            $rows[] = ['siswa_id' => $id, 'nama_lengkap' => $student?->nama_lengkap,
                'nisn' => $student?->nisn, 'nipd' => $student?->nipd,
                'valid' => $student !== null && !$closed && $conflicts->isEmpty(), 'conflicts'=>$details,
                'active_conflict' => $conflicts->contains(fn ($m) => $m->tanggal_mulai && $m->tanggal_mulai->toDateString() <= today('Asia/Jakarta')->toDateString() && (!$m->tanggal_selesai || $m->tanggal_selesai->toDateString() >= today('Asia/Jakarta')->toDateString())),
                'keterangan' => !$student ? 'Siswa tidak ditemukan.' : ($closed ? 'Siswa sudah memiliki keputusan lulus/pindah keluar.' : ($conflicts->isNotEmpty() ? 'Konflik: '.implode('; ', array_map(fn ($d) => ($d['tingkat']??'').' — '.($d['nama']??'Kelas').' ('.($d['tanggal_mulai']??'tidak valid').' s.d. '.($d['tanggal_selesai']??'terbuka').')', $details)) : 'Siap ditempatkan.'))];
            $state[] = [$id, $student?->nama_lengkap, $student?->nisn, $student?->nipd, $terminal->get($id, collect())->pluck('id')->all(),
                $history->map(fn ($m) => [$m->id, $m->kelas_id, $m->tanggal_mulai?->toDateString(), $m->tanggal_selesai?->toDateString()])->values()->all()];
        }
        return ['rows' => $rows, 'state' => [(int)$class->id, (int)$class->tahun_akademik_id, $class->nama, (int)$class->tingkat, $start, $state]];
    }

    public function issue(array $snapshot, int $actor, string $purpose): string
    {
        $expires = now()->addMinutes(5)->timestamp;
        return $expires.'.'.$this->signature($snapshot, $actor, $purpose, $expires);
    }

    private function signature(array $snapshot, int $actor, string $purpose, int $expires): string
    {
        return hash_hmac('sha256', json_encode([$snapshot['state'], $actor, $purpose, $expires], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function verify(array $snapshot, int $actor, string $purpose, string $token): void
    {
        [$expires, $signature] = array_pad(explode('.', $token, 2), 2, '');
        abort_unless(ctype_digit($expires) && (int)$expires > now()->timestamp && (int)$expires <= now()->addMinutes(5)->timestamp && hash_equals($this->signature($snapshot, $actor, $purpose, (int)$expires), $signature), 409, 'Preview sudah berubah atau kedaluwarsa. Periksa ulang sebelum menyimpan.');
    }

    public function place(Kelas $class, array $ids, string $start, int $actor, ?string $token = null, string $purpose = 'bulk'): array
    {
        abort_if(count($ids) < 1 || count($ids) > self::LIMIT || count(array_unique($ids)) !== count($ids), 422, 'Pilih 1–100 siswa unik per transaksi.');
        return DB::transaction(function () use ($class, $ids, $start, $actor, $token, $purpose) {
            $snapshot = $this->snapshot($class, $ids, $start, true);
            if ($token !== null) $this->verify($snapshot, $actor, $purpose, $token);
            foreach ($snapshot['rows'] as $row) {
                if (!$row['valid']) {
                    if ($token === null) abort(422, $row['active_conflict'] ? 'Siswa sudah memiliki kelas aktif pada tahun akademik ini.' : $row['keterangan']);
                    abort(422, 'Penempatan dibatalkan: terdapat konflik keanggotaan. Tidak ada data disimpan.');
                }
            }
            return array_map(fn ($id) => AnggotaKelas::create(['kelas_id' => $class->id, 'siswa_id' => $id, 'tanggal_mulai' => $start, 'tanggal_selesai' => null]), $ids);
        }, 3);
    }

    public function end(Kelas $class, Siswa $student, ?int $expectedId = null): void
    {
        DB::transaction(function () use ($class, $student, $expectedId) {
            Kelas::whereKey($class->id)->lockForUpdate()->firstOrFail();
            Siswa::whereKey($student->id)->lockForUpdate()->firstOrFail();
            $today = today('Asia/Jakarta')->toDateString();
            $members = AnggotaKelas::where('kelas_id', $class->id)->where('siswa_id', $student->id)
                ->whereDate('tanggal_mulai', '<=', $today)->where(fn ($q) => $q->whereNull('tanggal_selesai')->orWhereDate('tanggal_selesai', '>=', $today))->lockForUpdate()->get();
            abort_if($members->isEmpty(), 404, 'Siswa bukan anggota aktif dari kelas ini.');
            abort_if($members->count() !== 1 || ($expectedId !== null && (int)$members->first()->id !== $expectedId), 409, 'Keanggotaan berubah atau konflik. Muat ulang daftar.');
            $member = $members->first();
            $history=AnggotaKelas::where('siswa_id',$student->id)->whereHas('kelas',fn($q)=>$q->where('tahun_akademik_id',$class->tahun_akademik_id))->where('id','!=',$member->id)->lockForUpdate()->get();
            abort_if($history->contains(fn($m)=>\App\Support\AcademicTransitionRules::overlap($member->tanggal_mulai?->toDateString(),$today,$m->tanggal_mulai?->toDateString(),$m->tanggal_selesai?->toDateString())),409,'Riwayat keanggotaan konflik. Tinjau audit sebelum mengakhiri.');
            abort_if(AcademicMembershipEvent::where('source_membership_id',$member->id)->exists(),409,'Keanggotaan sudah memiliki keputusan transisi. Jangan ubah tanggal selesai.');
            // Do not shorten membership past dated academic records. Same-day end is inclusive.
            abort_if(Presensi::where('siswa_id', $student->id)->whereDate('tanggal', '>', $today)->whereHas('jadwalPelajaran', fn ($q) => $q->where('kelas_id', $class->id))->lockForUpdate()->first(['id']) !== null
                || SubmissionTugas::where('siswa_id', $student->id)->where('submitted_at', '>=', today('Asia/Jakarta')->addDay()->utc()->toDateTimeString())->whereHas('assignment.jadwalPelajaran', fn ($q) => $q->where('kelas_id', $class->id))->lockForUpdate()->first(['id']) !== null, 422, 'Terdapat catatan akademik setelah tanggal selesai. Periksa riwayat terlebih dahulu.');
            $member->update(['tanggal_selesai' => $today]);
        }, 3);
    }
}
