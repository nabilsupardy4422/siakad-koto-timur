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
                'numeric',
            ],
            'catatan' => [
                'sometimes',
                'nullable',
                'string',
            ],
        ];
    }
}