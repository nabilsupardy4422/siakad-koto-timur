<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kode' => [
                'required',
                'string',
                'max:50',
                'unique:mapel,kode',
            ],
            'nama' => [
                'required',
                'string',
                'max:255',
            ],
            'kkm' => [
                'nullable',
                'numeric',
            ],
        ];
    }
}