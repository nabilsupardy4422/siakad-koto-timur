<?php

namespace App\Http\Requests;

use App\Models\AnggotaKelas;
use App\Models\Presensi;
use Illuminate\Foundation\Http\FormRequest;
use App\Support\AttendanceTeachingAccess;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $attendance = $this->route('attendance');
        return $attendance instanceof Presensi && $attendance->jadwalPelajaran !== null
            && AttendanceTeachingAccess::canManage($this->user(), $attendance->jadwalPelajaran);
    }

    public function rules(): array
    {
        return [
            'tanggal' => [
                'sometimes',
                'date_format:Y-m-d',
                'before_or_equal:'.today('Asia/Jakarta')->toDateString(),
            ],

            'status' => [
                'sometimes',
                Rule::in([
                    'HADIR',
                    'IZIN',
                    'SAKIT',
                    'ALPA',
                ]),
            ],

            'catatan' => [
                'nullable',
                'string',
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }
                /** @var Presensi|null $attendance */
                $attendance = $this->route('attendance');

                if (!$attendance instanceof Presensi) {
                    return;
                }

                // Validate the effective date even for a status-only update.
                $tanggal = $this->input('tanggal', $attendance->tanggal?->toDateString());

                if (!is_string($tanggal)) {
                    return;
                }

                if ($tanggal > today('Asia/Jakarta')->toDateString()) {
                    $validator->errors()->add('tanggal', 'Tanggal presensi tidak boleh melebihi hari ini (WIB).');
                    return;
                }

                $isMember = AnggotaKelas::query()
                    ->where('kelas_id', $attendance->jadwalPelajaran?->kelas_id)
                    ->where('siswa_id', $attendance->siswa_id)
                    ->whereDate('tanggal_mulai', '<=', $tanggal)
                    ->where(function ($query) use ($tanggal): void {
                        $query
                            ->whereNull('tanggal_selesai')
                            ->orWhereDate('tanggal_selesai', '>=', $tanggal);
                    })
                    ->exists();

                if (!$isMember) {
                    $validator->errors()->add(
                        'tanggal',
                        'Siswa bukan anggota aktif dari kelas pada tanggal presensi tersebut.'
                    );
                }

                $duplicate = Presensi::query()
                    ->where(
                        'jadwal_pelajaran_id',
                        $attendance->jadwal_pelajaran_id
                    )
                    ->where('siswa_id', $attendance->siswa_id)
                    ->whereDate('tanggal', $tanggal)
                    ->where('id', '!=', $attendance->id)
                    ->exists();

                if ($duplicate) {
                    $validator->errors()->add(
                        'tanggal',
                        'Presensi untuk siswa, jadwal, dan tanggal tersebut sudah ada.'
                    );
                }
            },
        ];
    }
}
