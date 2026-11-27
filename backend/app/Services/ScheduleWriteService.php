<?php
namespace App\Services;

use App\Models\{Guru, JadwalPelajaran, Kelas, Mapel, TahunAkademik};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ScheduleWriteService
{
    public const DAYS = ['SENIN','SELASA','RABU','KAMIS','JUMAT','SABTU','MINGGU'];

    public static function transaction(callable $action): mixed
    {
        // All schedule writers acquire the same ordered period locks, including cross-period edits.
        return DB::transaction(function () use ($action) {
            TahunAkademik::lockForAdministrativeMutation();
            return $action();
        }, 3);
    }

    public static function teacherEligible(Guru $guru): bool
    {
        return $guru->user?->account_status === 'active' && $guru->user?->role?->code === 'GURU';
    }

    public static function validate(array $data, ?int $except = null): void
    {
        $errors = [];
        $class = Kelas::find($data['kelas_id']);
        if (!$class || (int)$class->tahun_akademik_id !== (int)$data['tahun_akademik_id']) $errors['tahun_akademik_id'][] = 'Periode kelas tidak sesuai.';
        if (!Mapel::find($data['mapel_id'])) $errors['mapel_id'][] = 'Mata pelajaran tidak ditemukan.';
        $guru = Guru::with('user.role')->find($data['guru_id']);
        if (!$guru || !self::teacherEligible($guru)) $errors['guru_id'][] = 'Guru tidak memenuhi syarat penjadwalan.';
        if (!in_array(strtoupper($data['hari']), self::DAYS, true)) $errors['hari'][] = 'Hari tidak valid.';
        $start = substr($data['jam_mulai'],0,5); $end = substr($data['jam_selesai'],0,5);
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$start) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$end) || $start >= $end) $errors['jam_selesai'][] = 'Rentang jam tidak valid.';
        $query = JadwalPelajaran::query()->where('tahun_akademik_id',$data['tahun_akademik_id'])
            ->whereRaw('UPPER(hari) = ?', [strtoupper($data['hari'])])
            ->whereRaw('SUBSTR(jam_mulai, 1, 5) < ?', [$end])->whereRaw('SUBSTR(jam_selesai, 1, 5) > ?', [$start])
            ->when($except !== null, fn($q) => $q->where('id','!=',$except));
        // Use current reads, not a stale MySQL consistent snapshot.
        foreach (['kelas_id','guru_id'] as $field) {
            if ((clone $query)->where($field,$data[$field])->lockForUpdate()->first(['id'])) $errors[$field][] = 'Jadwal bertabrakan pada waktu tersebut.';
        }
        if ($errors) throw ValidationException::withMessages($errors);
    }

    public static function version(JadwalPelajaran $row): string
    {
        return hash('sha256', json_encode([$row->id,$row->getRawOriginal('updated_at'),$row->only($row->getFillable())], JSON_THROW_ON_ERROR));
    }

    public static function protected(JadwalPelajaran $row): bool
    {
        foreach (['presensi','bahanAjar','komponenNilai','assignments'] as $relation) {
            if ($row->$relation()->lockForUpdate()->first(['id'])) return true;
        }
        return false;
    }

    public static function assertMutable(JadwalPelajaran $row, ?string $version): void
    {
        if ($version !== null && !hash_equals(self::version($row), $version)) abort(409,'Jadwal telah berubah. Muat ulang data.');
        if (self::protected($row)) abort(409,'Jadwal sudah memiliki riwayat akademik dan tidak boleh diubah atau dihapus.');
    }
}
