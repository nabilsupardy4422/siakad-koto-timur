<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Support\SchoolPasswordPolicy;

class ResetUserPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization dilakukan melalui middleware
        // auth:sanctum, role:TU dan permission khusus.
        return true;
    }

    public function rules(): array
    {
        return [
            'password' => SchoolPasswordPolicy::rules(),
        ];
    }

    public function messages(): array
    {
        return [
            'password.required' =>
                'Password baru wajib diisi.',

            'password.confirmed' =>
                'Konfirmasi password tidak sesuai.',
        ];
    }
}
