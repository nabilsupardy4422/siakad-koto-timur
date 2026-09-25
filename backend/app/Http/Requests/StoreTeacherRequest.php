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
            'user_id' => [
            'required',
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