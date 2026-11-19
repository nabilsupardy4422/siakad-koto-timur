<?php

namespace App\Http\Requests;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Autentikasi, role, dan permission
        // tetap diperiksa oleh middleware.
        return true;
    }

    public function rules(): array
    {
        return [
            'username' => [
                'required',
                'string',
                'max:255',
                Rule::unique('users', 'username'),
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
                Rule::unique('users', 'email'),
            ],

            'role_id' => [
                'required',
                'integer',
                Rule::exists('roles', 'id'),
            ],

            'password' => [
                'required',
                'string',
                Password::defaults(),
            ],
        ];
    }

    public function withValidator(
        Validator $validator
    ): void {
        $validator->after(
            function (Validator $validator): void {
                $roleId = $this->input('role_id');

                if (! is_numeric($roleId)) {
                    return;
                }

                $roleCode = Role::query()
                    ->whereKey($roleId)
                    ->value('code');

                if (
                    $roleCode !== null &&
                    ! in_array(
                        $roleCode,
                        [
                            'TU',
                            'KEPALA_SEKOLAH',
                            'GURU',
                            'SISWA',
                        ],
                        true
                    )
                ) {
                    $validator->errors()->add(
                        'role_id',
                        'Role yang dipilih tidak valid untuk akun SIAKAD.'
                    );
                }
            }
        );
    }

    public function messages(): array
    {
        return [
            'username.required' =>
                'Username wajib diisi.',

            'username.unique' =>
                'Username sudah digunakan.',

            'name.required' =>
                'Nama pengguna wajib diisi.',

            'email.email' =>
                'Format email tidak valid.',

            'email.unique' =>
                'Email sudah digunakan.',

            'role_id.required' =>
                'Role pengguna wajib dipilih.',

            'role_id.exists' =>
                'Role tidak ditemukan.',

            'password.required' =>
                'Password awal wajib diisi.',
        ];
    }
}
