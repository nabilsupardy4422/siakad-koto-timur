<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateTeacherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $teacherId = $this->route('teacher')?->id
            ?? $this->route('teacher');

        return [
            'profile_version' => ['sometimes', 'required', 'string', 'size:64'],
            'user_id' => [
                'required',
                'integer',
                'exists:users,id',
                Rule::unique('guru', 'user_id')->ignore($teacherId),
            ],
            'nip' => [
                'required',
                'string',
                'max:50',
                Rule::unique('guru', 'nip')->ignore($teacherId),
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
            if ($validator->errors()->has('user_id')) return;
            $profile = $this->route('teacher');
            if ($profile && (int) $profile->user_id !== (int) $this->input('user_id')) {
                $validator->errors()->add('user_id', 'Relasi akun profil tidak dapat diubah.');
            }
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
