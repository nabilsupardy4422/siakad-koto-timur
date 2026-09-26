<?php

namespace App\Http\Requests;

use App\Models\Kelas;
use App\Models\WaliKelas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreWaliKelasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'guru_id' => [
                'required',
                'integer',
                'exists:guru,id',
            ],

            'kelas_id' => [
                'required',
                'integer',
                'exists:kelas,id',
            ],

            'tahun_akademik_id' => [
                'required',
                'integer',
                'exists:tahun_akademik,id',
            ],

            'tanggal_mulai' => [
                'required',
                'date',
            ],

            'tanggal_selesai' => [
                'nullable',
                'date',
                'after_or_equal:tanggal_mulai',
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $kelasId = $this->input('kelas_id');
                $tahunAkademikId = $this->input('tahun_akademik_id');

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

                $hasActiveAssignment = WaliKelas::query()
                    ->where('kelas_id', $class->id)
                    ->where('tahun_akademik_id', $class->tahun_akademik_id)
                    ->whereNull('tanggal_selesai')
                    ->exists();

                if ($hasActiveAssignment) {
                    $validator->errors()->add(
                        'kelas_id',
                        'Kelas tersebut sudah memiliki Wali Kelas aktif pada tahun akademik ini.'
                    );
                }
            },
        ];
    }
}