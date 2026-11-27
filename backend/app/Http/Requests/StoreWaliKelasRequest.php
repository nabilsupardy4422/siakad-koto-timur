<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
                'date_format:Y-m-d',
            ],

            'tanggal_selesai' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:tanggal_mulai',
            ],
        ];
    }

}
