<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAcademicYearRequest extends FormRequest
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
                    )
                    ->ignore($this->route('academicYear')->id),
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
            'expected_active_ids' => ['sometimes', 'array'],
            'expected_active_ids.*' => ['integer', 'min:1', 'distinct'],
        ];
    }

    public function after(): array
    {
        return [function (\Illuminate\Validation\Validator $validator): void {
            $period = $this->route('academicYear');
            if ($this->boolean('is_active') && (int) $this->input('tahun_mulai') === $period->tahun_mulai
                && (int) $this->input('tahun_selesai') === $period->tahun_selesai && $this->input('semester') === $period->semester) {
                return; // Preserve legacy identities; do not rewrite historical data.
            }
            if (! $validator->errors()->has('tahun_mulai') && ! $validator->errors()->has('tahun_selesai')
                && ((int) $this->input('tahun_mulai') < 1000 || (int) $this->input('tahun_selesai') !== (int) $this->input('tahun_mulai') + 1)) {
                $validator->errors()->add('tahun_selesai', 'Tahun selesai harus satu tahun setelah tahun mulai.');
            }
        }];
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
