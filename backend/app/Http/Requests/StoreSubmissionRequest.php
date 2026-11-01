<?php

namespace App\Http\Requests;

use App\Models\Assignment;
use App\Models\TahunAkademik;
use Illuminate\Foundation\Http\FormRequest;

class StoreSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user) {
            return false;
        }

        if ($user->role?->code !== 'SISWA') {
            return false;
        }

        $student = $user->siswa;

        if (! $student) {
            return false;
        }

        $assignment = $this->route('assignment');

        if (! $assignment instanceof Assignment) {
            return false;
        }

        $assignment->loadMissing([
            'jadwalPelajaran',
        ]);

        $schedule = $assignment->jadwalPelajaran;

        if (! $schedule) {
            return false;
        }

        $activeAcademicYearId = TahunAkademik::query()
            ->where('is_active', true)
            ->value('id');

        if (
            $activeAcademicYearId === null ||
            (int) $schedule->tahun_akademik_id !==
                (int) $activeAcademicYearId
        ) {
            return false;
        }

        return $student->anggotaKelas()
            ->where('kelas_id', $schedule->kelas_id)
            ->whereNull('tanggal_selesai')
            ->exists();
    }

    public function rules(): array
    {
        return [
            'jawaban' => [
                'nullable',
                'string',
            ],

            // Kompatibilitas dengan frontend lama.
            'file' => [
                'nullable',
                'file',
                'max:10240',
                'mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx,ppt,pptx',
            ],

            // Maksimal 10 file dalam satu submission.
            'files' => [
                'nullable',
                'array',
                'max:10',
            ],

            // Maksimal 10 MB untuk setiap file.
            'files.*' => [
                'file',
                'max:10240',
                'mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx,ppt,pptx',
            ],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $jawaban = $this->input('jawaban');
            $file = $this->file('file');
            $files = $this->file('files', []);

            $hasJawaban = is_string($jawaban)
                ? trim($jawaban) !== ''
                : filled($jawaban);

            $hasMultipleFiles = is_array($files)
                && count($files) > 0;

            $hasFile = $file !== null || $hasMultipleFiles;

            if (! $hasJawaban && ! $hasFile) {
                $validator->errors()->add(
                    'jawaban',
                    'Jawaban atau file wajib diisi.'
                );

                $validator->errors()->add(
                    'file',
                    'Jawaban atau file wajib diisi.'
                );
            }
        });
    }

    public function attributes(): array
    {
        return [
            'jawaban' => 'jawaban',
            'file' => 'file',
            'files' => 'daftar file',
            'files.*' => 'file lampiran',
        ];
    }
}
