<?php

namespace App\Http\Requests;

use App\Models\Kelas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreClassMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'siswa_id' => [
                'required',
                'integer',
                'exists:siswa,id',
            ],

            'tanggal_mulai' => [
                'required',
                'date',
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $class = $this->route('class');

                if (! $class instanceof Kelas) {
                    return;
                }

                $studentId = $this->input('siswa_id');
                $tanggalMulai = $this->input('tanggal_mulai');

                if (
                    ! is_numeric($studentId) ||
                    empty($tanggalMulai)
                ) {
                    return;
                }

                $exists = \App\Models\AnggotaKelas::query()
                    ->where('kelas_id', $class->id)
                    ->where('siswa_id', (int) $studentId)
                    ->whereDate('tanggal_mulai', $tanggalMulai)
                    ->exists();

                if ($exists) {
                    $validator->errors()->add(
                        'tanggal_mulai',
                        'Tanggal mulai keanggotaan siswa sudah digunakan pada kelas ini.'
                    );
                }
            },
        ];
    }
}