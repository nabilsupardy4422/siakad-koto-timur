<?php

namespace App\Http\Requests;

use App\Models\JadwalPelajaran;
use App\Models\Kelas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tahun_akademik_id' => [
                'sometimes',
                'integer',
                'exists:tahun_akademik,id',
            ],
            'kelas_id' => [
                'sometimes',
                'integer',
                'exists:kelas,id',
            ],
            'mapel_id' => [
                'sometimes',
                'integer',
                'exists:mapel,id',
            ],
            'guru_id' => [
                'sometimes',
                'integer',
                'exists:guru,id',
            ],
            'hari' => [
                'sometimes',
                'string',
                'max:20',
            ],
            'jam_mulai' => [
                'sometimes',
                'date_format:H:i',
            ],
            'jam_selesai' => [
                'sometimes',
                'date_format:H:i',
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $schedule = $this->route('schedule');

                if (! $schedule instanceof JadwalPelajaran) {
                    return;
                }

                $tahunAkademikId = (int) $this->input(
                    'tahun_akademik_id',
                    $schedule->tahun_akademik_id
                );

                $kelasId = (int) $this->input(
                    'kelas_id',
                    $schedule->kelas_id
                );

                $guruId = (int) $this->input(
                    'guru_id',
                    $schedule->guru_id
                );

                $hari = $this->input(
                    'hari',
                    $schedule->hari
                );

                $jamMulai = $this->input(
                    'jam_mulai',
                    $schedule->jam_mulai
                );

                $jamSelesai = $this->input(
                    'jam_selesai',
                    $schedule->jam_selesai
                );

                if (
                    ! is_string($hari) ||
                    ! is_string($jamMulai) ||
                    ! is_string($jamSelesai)
                ) {
                    return;
                }

                $class = Kelas::query()->find($kelasId);

                if (! $class) {
                    return;
                }

                if (
                    (int) $class->tahun_akademik_id !==
                    $tahunAkademikId
                ) {
                    $validator->errors()->add(
                        'tahun_akademik_id',
                        'Tahun akademik tidak sesuai dengan tahun akademik kelas.'
                    );
                }

                if (
                    strtotime($jamSelesai) <=
                    strtotime($jamMulai)
                ) {
                    $validator->errors()->add(
                        'jam_selesai',
                        'Jam selesai harus lebih besar dari jam mulai.'
                    );
                }

                $hasClassConflict = $this->hasConflict(
                    kelasId: $kelasId,
                    guruId: null,
                    tahunAkademikId: $tahunAkademikId,
                    hari: $hari,
                    jamMulai: $jamMulai,
                    jamSelesai: $jamSelesai,
                    exceptId: $schedule->id,
                );

                if ($hasClassConflict) {
                    $validator->errors()->add(
                        'kelas_id',
                        'Kelas sudah memiliki jadwal yang bertabrakan pada waktu tersebut.'
                    );
                }

                $hasTeacherConflict = $this->hasConflict(
                    kelasId: null,
                    guruId: $guruId,
                    tahunAkademikId: $tahunAkademikId,
                    hari: $hari,
                    jamMulai: $jamMulai,
                    jamSelesai: $jamSelesai,
                    exceptId: $schedule->id,
                );

                if ($hasTeacherConflict) {
                    $validator->errors()->add(
                        'guru_id',
                        'Guru sudah memiliki jadwal yang bertabrakan pada waktu tersebut.'
                    );
                }
            },
        ];
    }

    private function hasConflict(
        ?int $kelasId,
        ?int $guruId,
        int $tahunAkademikId,
        string $hari,
        string $jamMulai,
        string $jamSelesai,
        int $exceptId,
    ): bool {
        return JadwalPelajaran::query()
            ->where('tahun_akademik_id', $tahunAkademikId)
            ->where('hari', $hari)
            ->whereKeyNot($exceptId)
            ->when(
                $kelasId !== null,
                fn ($query) => $query->where('kelas_id', $kelasId)
            )
            ->when(
                $guruId !== null,
                fn ($query) => $query->where('guru_id', $guruId)
            )
            ->where(function ($query) use (
                $jamMulai,
                $jamSelesai
            ): void {
                $query
                    ->where(
                        'jam_mulai',
                        '<',
                        $jamSelesai
                    )
                    ->where(
                        'jam_selesai',
                        '>',
                        $jamMulai
                    );
            })
            ->exists();
    }
}