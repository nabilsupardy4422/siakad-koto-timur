<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSubmissionRequest extends FormRequest
{
    /**
     * Determine whether the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user) {
            return false;
        }

        // Revisi submission hanya dapat dilakukan oleh siswa.
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

        // Siswa hanya dapat merevisi submission miliknya sendiri.
        return (int) $submission->siswa_id === (int) $student->id;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'jawaban' => [
                'nullable',
                'string',
            ],

            'file' => [
                'nullable',
                'file',
                'max:10240',
                'mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx,ppt,pptx',
            ],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        /*
         * Tidak melakukan validasi "jawaban atau file wajib"
         * pada tahap request.
         *
         * Pada PUT, field yang tidak dikirim dapat berarti
         * data sebelumnya tetap dipertahankan.
         *
         * Validasi akhir mengenai minimal satu sumber jawaban
         * dilakukan oleh SubmissionController setelah submission
         * lama dimuat.
         */
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'jawaban' => 'jawaban',
            'file' => 'file',
        ];
    }
}