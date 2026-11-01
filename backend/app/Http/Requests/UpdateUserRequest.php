<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $user = $this->route('user');
        $userId = $user?->id;

        return [
            'username' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('users', 'username')->ignore($userId),
            ],
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],
            'email' => [
                'sometimes',
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'role_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('roles', 'id'),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->exists('role_id')) {
                return;
            }

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
