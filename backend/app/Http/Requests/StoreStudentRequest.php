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
            'user_id' => [
                'required',
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