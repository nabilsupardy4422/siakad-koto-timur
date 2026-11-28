<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWaliKelasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'version' => ['sometimes', 'required', 'string', 'size:64'],
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
                'date_format:Y-m-d',
            ],

            'tanggal_selesai' => [
                'sometimes',
                'nullable',
                'date_format:Y-m-d',
            ],
        ];
    }

}
