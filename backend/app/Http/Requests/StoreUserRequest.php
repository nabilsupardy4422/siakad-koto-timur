<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username' => [
                'required',
                'string',
                'max:255',
                'unique:users,username',
            ],
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'email' => [
                'nullable',
                'email',
                'max:255',
                'unique:users,email',
            ],
            'role_id' => [
                'required',
                'integer',
                Rule::exists('roles', 'id'),
            ],
            'password' => [
                'required',
                'string',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $roleId = $this->input('role_id');

            if (! is_numeric($roleId)) {
                return;
            }

            $roleCode = \App\Models\Role::query()
                ->whereKey($roleId)
                ->value('code');

            if (
                $roleCode !== null
                && ! in_array(
                    $roleCode,
                    ['TU', 'KEPALA_SEKOLAH', 'GURU', 'SISWA'],
                    true
                )
            ) {
                $validator->errors()->add(
                    'role_id',
                    'Role yang dipilih tidak valid untuk akun SIAKAD.'
                );
            }
        });
    }
}
