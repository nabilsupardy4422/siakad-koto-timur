<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role?->code === 'GURU';
    }

    public function rules(): array
    {
        return [
            'nilai' => [
                'sometimes',
                'required',
                'numeric',
                'between:0,100',
            ],
            'catatan' => [
                'sometimes',
                'nullable',
                'string',
            ],
        ];
    }
}
