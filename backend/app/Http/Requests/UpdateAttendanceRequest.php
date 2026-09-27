<?php

namespace App\Http\Requests;

use App\Models\AnggotaKelas;
use App\Models\Presensi;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tanggal' => [
                'sometimes',
                'date',
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
                /** @var Presensi|null $attendance */
                $attendance = $this->route('attendance');

                if (!$attendance instanceof Presensi) {
                    return;
                }

                // Jika tanggal tidak diubah, tidak perlu melakukan
                // validasi membership/duplicate berdasarkan tanggal baru.
                if (!$this->has('tanggal')) {
                    return;
                }

                $tanggal = $this->input('tanggal');

                if (!is_string($tanggal)) {
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