<?php

namespace App\Http\Requests;

use App\Models\Kelas;
use App\Models\WaliKelas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateWaliKelasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'guru_id' => [
                'sometimes',
                'integer',
                'exists:guru,id',
            ],

            'kelas_id' => [
                'sometimes',
                'integer',
                'exists:kelas,id',
            ],

            'tahun_akademik_id' => [
                'sometimes',
                'integer',
                'exists:tahun_akademik,id',
            ],

            'tanggal_mulai' => [
                'sometimes',
                'date',
            ],

            'tanggal_selesai' => [
                'nullable',
                'date',
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $assignment = $this->route('assignment');

                if (! $assignment instanceof WaliKelas) {
                    return;
                }

                $kelasId = $this->input(
                    'kelas_id',
                    $assignment->kelas_id
                );

                $tahunAkademikId = $this->input(
                    'tahun_akademik_id',
                    $assignment->tahun_akademik_id
                );

                $tanggalMulai = $this->input(
                    'tanggal_mulai',
                    $assignment->tanggal_mulai?->toDateString()
                );

                $tanggalSelesai = $this->input(
                    'tanggal_selesai',
                    $assignment->tanggal_selesai?->toDateString()
                );

                if (! is_numeric($kelasId) || ! is_numeric($tahunAkademikId)) {
                    return;
                }

                $class = Kelas::query()->find((int) $kelasId);

                if (! $class) {
                    return;
                }

                if ((int) $class->tahun_akademik_id !== (int) $tahunAkademikId) {
                    $validator->errors()->add(
                        'tahun_akademik_id',
                        'Tahun akademik tidak sesuai dengan tahun akademik kelas.'
                    );
                }

                if (
                    $tanggalMulai !== null &&
                    $tanggalSelesai !== null &&
                    strtotime($tanggalSelesai) < strtotime($tanggalMulai)
                ) {
                    $validator->errors()->add(
                        'tanggal_selesai',
                        'Tanggal selesai tidak boleh lebih awal dari tanggal mulai.'
                    );
                }

                $hasAnotherActiveAssignment = WaliKelas::query()
                    ->where('kelas_id', (int) $kelasId)
                    ->where(
                        'tahun_akademik_id',
                        (int) $tahunAkademikId
                    )
                    ->whereNull('tanggal_selesai')
                    ->whereKeyNot($assignment->id)
                    ->exists();

                if ($hasAnotherActiveAssignment) {
                    $validator->errors()->add(
                        'kelas_id',
                        'Kelas tersebut sudah memiliki Wali Kelas aktif pada tahun akademik ini.'
                    );
                }
            },
        ];
    }
}