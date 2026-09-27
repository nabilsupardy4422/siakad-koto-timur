<?php

namespace App\Http\Requests;

use App\Models\JadwalPelajaran;
use App\Models\Kelas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tahun_akademik_id' => [
                'required',
                'integer',
                'exists:tahun_akademik,id',
            ],
            'kelas_id' => [
                'required',
                'integer',
                'exists:kelas,id',
            ],
            'mapel_id' => [
                'required',
                'integer',
                'exists:mapel,id',
            ],
            'guru_id' => [
                'required',
                'integer',
                'exists:guru,id',
            ],
            'hari' => [
                'required',
                'string',
                'max:20',
            ],
            'jam_mulai' => [
                'required',
                'date_format:H:i',
            ],
            'jam_selesai' => [
                'required',
                'date_format:H:i',
                'after:jam_mulai',
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $kelasId = $this->input('kelas_id');
                $tahunAkademikId = $this->input('tahun_akademik_id');
                $guruId = $this->input('guru_id');
                $hari = $this->input('hari');
                $jamMulai = $this->input('jam_mulai');
                $jamSelesai = $this->input('jam_selesai');

                if (
                    ! is_numeric($kelasId) ||
                    ! is_numeric($tahunAkademikId) ||
                    ! is_numeric($guruId) ||
                    ! is_string($hari) ||
                    ! is_string($jamMulai) ||
                    ! is_string($jamSelesai)
                ) {
                    return;
                }

                $class = Kelas::query()->find((int) $kelasId);

                if (! $class) {
                    return;
                }

                if (
                    (int) $class->tahun_akademik_id !==
                    (int) $tahunAkademikId
                ) {
                    $validator->errors()->add(
                        'tahun_akademik_id',
                        'Tahun akademik tidak sesuai dengan tahun akademik kelas.'
                    );

                    return;
                }

                $hasClassConflict = $this->hasConflict(
                    kelasId: (int) $kelasId,
                    guruId: null,
                    tahunAkademikId: (int) $tahunAkademikId,
                    hari: $hari,
                    jamMulai: $jamMulai,
                    jamSelesai: $jamSelesai,
                );

                if ($hasClassConflict) {
                    $validator->errors()->add(
                        'kelas_id',
                        'Kelas sudah memiliki jadwal yang bertabrakan pada waktu tersebut.'
                    );
                }

                $hasTeacherConflict = $this->hasConflict(
                    kelasId: null,
                    guruId: (int) $guruId,
                    tahunAkademikId: (int) $tahunAkademikId,
                    hari: $hari,
                    jamMulai: $jamMulai,
                    jamSelesai: $jamSelesai,
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
    ): bool {
        return JadwalPelajaran::query()
            ->where('tahun_akademik_id', $tahunAkademikId)
            ->where('hari', $hari)
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