<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSubmissionRequest extends FormRequest
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

        $submission = $this->route('submission');

        if (! $submission) {
            return false;
        }

        // Siswa hanya boleh merevisi submission miliknya.
        return (int) $submission->siswa_id
            === (int) $student->id;
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

            // Maksimal 10 file tambahan per request.
            'files' => [
                'nullable',
                'array',
                'max:10',
            ],

            'files.*' => [
                'file',
                'max:10240',
                'mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx,ppt,pptx',
            ],
        ];
    }

    public function withValidator($validator): void
    {
        /*
         * Field yang tidak dikirim saat revisi akan
         * mempertahankan data sebelumnya.
         *
         * Validasi akhir bahwa submission memiliki
         * jawaban atau file dilakukan oleh controller.
         */
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
