<?php

namespace App\Http\Requests;

use App\Services\Reporting\ReportAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReportRequest extends FormRequest
{
    private const REPORT_FILTERS = [
        'students' => [
            'academic_year_id',
            'class_id',
            'subject_id',
            'schedule_id',
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
            'schedule_id',
        ],
        'attendance' => [
            'academic_year_id',
            'semester',
            'class_id',
            'student_id',
            'subject_id',
            'schedule_id',
            'date_from',
            'date_to',
        ],
        'grades' => [
            'academic_year_id',
            'semester',
            'class_id',
            'subject_id',
            'schedule_id',
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
        return $this->user() !== null
            && (! array_key_exists($this->route('reportType'), self::REPORT_FILTERS)
                || ReportAccess::allowsType($this->user(), $this->route('reportType')));
    }

    public function rules(): array
    {
        return [
            'confirm_empty' => $this->routeIs('student.reports.export') ? ['nullable','boolean'] : ['prohibited'],
            'scope' => ['nullable', 'string', Rule::in(['school', 'teaching', 'homeroom', 'own'])],
            'schedule_id' => ['nullable', 'integer', 'exists:jadwal_pelajaran,id'],
            'format' => $this->routeIs('reports.preview', 'student.reports.preview') ? ['prohibited'] : ['required', 'string', Rule::in(['pdf', 'excel'])],
            'page' => $this->routeIs('reports.preview', 'student.reports.preview') ? ['nullable', 'integer', 'min:1', 'max:1000000'] : ['prohibited'],
            'preview_hash' => $this->routeIs('reports.preview', 'student.reports.preview') ? ['prohibited'] : ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/'],
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
                $this->routeIs('student.reports.*') ? 'date_format:Y-m-d' : 'date',
            ],
            'date_to' => [
                'nullable',
                $this->routeIs('student.reports.*') ? 'date_format:Y-m-d' : 'date',
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
                [$this->routeIs('reports.preview', 'student.reports.preview') ? 'page' : 'format', 'scope', ...($this->routeIs('reports.preview', 'student.reports.preview') ? [] : ['preview_hash'])],
                self::REPORT_FILTERS[$reportType]
            );

            if ($this->routeIs('student.reports.*')) {
                $allowed = array_merge(['academic_year_id'], $this->routeIs('student.reports.preview') ? ['page'] : ['format','preview_hash','confirm_empty']);
                if ($reportType === 'attendance') $allowed = [...$allowed,'date_from','date_to'];
                if (! $this->filled('academic_year_id')) $validator->errors()->add('academic_year_id','Pilih periode.');
                if ($this->routeIs('student.reports.export') && ! $this->filled('preview_hash')) $validator->errors()->add('preview_hash','Pratinjau diperlukan.');
            }
            foreach ($this->all() as $key => $value) {
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
