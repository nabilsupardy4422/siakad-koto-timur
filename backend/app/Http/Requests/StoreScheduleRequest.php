<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreScheduleRequest extends FormRequest
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
                'in:SENIN,SELASA,RABU,KAMIS,JUMAT,SABTU,MINGGU',
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

}
