<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $subject = $this->route('subject');

        return [
            'kode' => [
                'required',
                'string',
                'max:50',
                Rule::unique('mapel', 'kode')->ignore($subject->id),
            ],
            'nama' => [
                'required',
                'string',
                'max:255',
            ],
            'version' => ['nullable', 'string', 'size:64'],
            'kkm' => [
                'nullable',
                'numeric',
                'between:0,100',
                'decimal:0,2',
            ],
        ];
    }
}
