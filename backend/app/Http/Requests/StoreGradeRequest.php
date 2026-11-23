<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreGradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role?->code === 'GURU';
    }

    public function rules(): array
    {
        return [
            'komponen_nilai_id' => [
                'required',
                'integer',
                'exists:komponen_nilai,id',
            ],
            'siswa_id' => [
                'required',
                'integer',
                'exists:siswa,id',
            ],
            'nilai' => [
                'required',
                'numeric',
                'between:0,100',
            ],
            'catatan' => [
                'nullable',
                'string',
            ],
        ];
    }
}
