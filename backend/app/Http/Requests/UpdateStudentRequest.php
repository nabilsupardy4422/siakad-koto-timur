<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $studentId = $this->route('student')?->id
            ?? $this->route('student');

        return [
            'user_id' => [
                'required',
                'integer',
                'exists:users,id',
                Rule::unique('siswa', 'user_id')->ignore($studentId),
            ],
            'nisn' => [
                'required',
                'string',
                'max:50',
                Rule::unique('siswa', 'nisn')->ignore($studentId),
            ],
            'nis' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('siswa', 'nis')->ignore($studentId),
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