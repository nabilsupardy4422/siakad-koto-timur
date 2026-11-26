<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreStudentRequest extends FormRequest
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
                'unique:siswa,user_id',
            ],
            'nisn' => [
                'required',
                'string',
                'max:50',
                'unique:siswa,nisn',
            ],
            'nipd' => ['nullable', 'string', 'max:50', 'unique:siswa,nipd'],
            'nis' => [
                'nullable',
                'string',
                'max:50',
                'unique:siswa,nis',
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
            'tanggal_lahir' => [
                'nullable',
                'date',
                'before_or_equal:today',
            ],
            'tempat_lahir' => [
                'nullable',
                'string',
                'max:100',
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

            if ($user !== null && $user->role?->code !== 'SISWA') {
                $validator->errors()->add(
                    'user_id',
                    'User yang dipilih harus memiliki role SISWA.'
                );
            }
        });
    }
}
