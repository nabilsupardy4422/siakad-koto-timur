<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'jadwal_pelajaran_id' => [
                'sometimes',
                'integer',
                'exists:jadwal_pelajaran,id',
            ],

            'judul' => [
                'sometimes',
                'string',
                'max:255',
            ],

            'deskripsi' => [
                'nullable',
                'string',
            ],

            'file' => [
                'sometimes',
                'nullable',
                'file',
            ],

            'remove_file' => [
                'sometimes',
                'boolean',
            ],
        ];
    }
}