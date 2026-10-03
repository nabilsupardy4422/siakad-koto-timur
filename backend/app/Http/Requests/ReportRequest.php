<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReportRequest extends FormRequest
{
    private const REPORT_FILTERS = [
        'students' => [
            'academic_year_id',
            'class_id',
            'search',
        ],
        'teachers' => [
            'search',
            'status',
        ],
        'classes' => [
            'academic_year_id',
        ],
        'schedules' => [
            'academic_year_id',
            'semester',
            'class_id',
            'subject_id',
            'teacher_id',
        ],
        'attendance' => [
            'academic_year_id',
            'semester',
            'class_id',
            'student_id',
            'date_from',
            'date_to',
        ],
        'grades' => [
            'academic_year_id',
            'semester',
            'class_id',
            'subject_id',
            'student_id',
        ],
        'semester-recap' => [
            'academic_year_id',
            'semester',
            'class_id',
            'student_id',
        ],
    ];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'format' => [
                'required',
                'string',
                Rule::in(['pdf', 'excel']),
            ],
            'academic_year_id' => [
                'nullable',
                'integer',
                'exists:tahun_akademik,id',
            ],
            'semester' => [
                'nullable',
                'string',
                'max:50',
            ],
            'class_id' => [
                'nullable',
                'integer',
                'exists:kelas,id',
            ],
            'subject_id' => [
                'nullable',
                'integer',
                'exists:mapel,id',
            ],
            'teacher_id' => [
                'nullable',
                'integer',
                'exists:guru,id',
            ],
            'student_id' => [
                'nullable',
                'integer',
                'exists:siswa,id',
            ],
            'search' => [
                'nullable',
                'string',
                'max:100',
            ],
            'status' => [
                'nullable',
                'string',
                'max:50',
            ],
            'date_from' => [
                'nullable',
                'date',
            ],
            'date_to' => [
                'nullable',
                'date',
                'after_or_equal:date_from',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $reportType = $this->route('reportType');

            if (! array_key_exists($reportType, self::REPORT_FILTERS)) {
                $validator->errors()->add(
                    'reportType',
                    'Jenis laporan tidak didukung.'
                );

                return;
            }

            $allowed = array_merge(
                ['format'],
                self::REPORT_FILTERS[$reportType]
            );

            foreach ($this->query() as $key => $value) {
                if (! in_array($key, $allowed, true)) {
                    $validator->errors()->add(
                        $key,
                        "Filter '{$key}' tidak didukung untuk laporan {$reportType}."
                    );
                }
            }
        });
    }

    public function filters(): array
    {
        return $this->validated();
    }
}