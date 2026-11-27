<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('hari'))) $this->merge(['hari' => strtoupper(trim($this->input('hari')))]);
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
                'in:SENIN,SELASA,RABU,KAMIS,JUMAT,SABTU,MINGGU',
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

}
