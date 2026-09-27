<?php

namespace App\Http\Requests;

use App\Models\JadwalPelajaran;
use App\Models\Siswa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'jadwal_pelajaran_id' => [
                'required',
                'integer',
                'exists:jadwal_pelajaran,id',
            ],

            'siswa_id' => [
                'required',
                'integer',
                'exists:siswa,id',
            ],

            'tanggal' => [
                'required',
                'date',
            ],

            'status' => [
                'required',
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
                $scheduleId = $this->input('jadwal_pelajaran_id');
                $studentId = $this->input('siswa_id');
                $tanggal = $this->input('tanggal');

                if (
                    ! is_numeric($scheduleId)
                    || ! is_numeric($studentId)
                    || ! is_string($tanggal)
                ) {
                    return;
                }

                $schedule = JadwalPelajaran::query()
                    ->find((int) $scheduleId);

                $student = Siswa::query()
                    ->find((int) $studentId);

                if (! $schedule || ! $student) {
                    return;
                }

                $isMember = $student->anggotaKelas()
                    ->where('kelas_id', $schedule->kelas_id)
                    ->whereDate('tanggal_mulai', '<=', $tanggal)
                    ->where(function ($query) use ($tanggal): void {
                        $query
                            ->whereNull('tanggal_selesai')
                            ->orWhereDate('tanggal_selesai', '>=', $tanggal);
                    })
                    ->exists();

                if (! $isMember) {
                    $validator->errors()->add(
                        'siswa_id',
                        'Siswa bukan anggota aktif dari kelas pada tanggal presensi tersebut.'
                    );
                }

                $duplicate = \App\Models\Presensi::query()
                    ->where('jadwal_pelajaran_id', (int) $scheduleId)
                    ->where('siswa_id', (int) $studentId)
                    ->whereDate('tanggal', $tanggal)
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