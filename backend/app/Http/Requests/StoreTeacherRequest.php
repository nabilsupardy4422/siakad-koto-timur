<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreTeacherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'registration_mode' => ['sometimes', 'in:new_account,existing_account'],
            'username' => ['nullable', 'string', 'max:100', 'prohibited_unless:registration_mode,new_account'],
            'user_id' => [
            'required_unless:registration_mode,new_account',
                'prohibited_if:registration_mode,new_account',
                'nullable',
            'integer',
            'exists:users,id',
            'unique:guru,user_id',
            ],
            'nip' => [
                'required',
                'string',
                'max:50',
                'unique:guru,nip',
            ],
            'nama_lengkap' => [
                'required',
                'string',
                'max:255',
            ],
            'jenis_kelamin' => [
                'nullable',
                'string',
                'max:20',
            ],
            'no_telepon' => [
                'nullable',
                'string',
                'max:30',
            ],
            'alamat' => [
                'nullable',
                'string',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('user_id') || ! $this->filled('user_id')) {
                return;
            }
            $user = User::with('role')->find($this->input('user_id'));

            if ($user !== null && $user->role?->code !== 'GURU') {
                $validator->errors()->add(
                    'user_id',
                    'User yang dipilih harus memiliki role GURU.'
                );
            }
        });
    }
}
