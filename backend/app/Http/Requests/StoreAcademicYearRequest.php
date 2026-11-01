<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAcademicYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tahun_mulai' => [
                'required',
                'integer',
                'digits:4',
                Rule::unique('tahun_akademik', 'tahun_mulai')
                    ->where(fn ($query) => $query
                        ->where('tahun_selesai', $this->input('tahun_selesai'))
                        ->where('semester', $this->input('semester'))
                    ),
            ],
            'tahun_selesai' => [
                'required',
                'integer',
                'digits:4',
            ],
            'semester' => [
                'required',
                Rule::in(['GANJIL', 'GENAP']),
            ],
            'is_active' => [
                'required',
                'boolean',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'tahun_mulai.required' => 'Tahun mulai wajib diisi.',
            'tahun_mulai.integer' => 'Tahun mulai harus berupa angka.',
            'tahun_mulai.digits' => 'Tahun mulai harus terdiri dari 4 digit.',
            'tahun_mulai.unique' => 'Kombinasi tahun akademik dan semester tersebut sudah terdaftar.',

            'tahun_selesai.required' => 'Tahun selesai wajib diisi.',
            'tahun_selesai.integer' => 'Tahun selesai harus berupa angka.',
            'tahun_selesai.digits' => 'Tahun selesai harus terdiri dari 4 digit.',

            'semester.required' => 'Semester wajib diisi.',
            'semester.in' => 'Semester harus GANJIL atau GENAP.',

            'is_active.required' => 'Status aktif wajib diisi.',
            'is_active.boolean' => 'Status aktif harus berupa boolean.',
        ];
    }
}