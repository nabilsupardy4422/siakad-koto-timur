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
            'profile_version' => ['sometimes', 'required', 'string', 'size:64'],
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
            'nipd' => ['nullable', 'string', 'max:50', Rule::unique('siswa', 'nipd')->ignore($studentId)],
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
            if ($validator->errors()->has('user_id')) return;
            $profile = $this->route('student');
            if ($profile && (int) $profile->user_id !== (int) $this->input('user_id')) {
                $validator->errors()->add('user_id', 'Relasi akun profil tidak dapat diubah.');
            }
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
