<?php

namespace App\Http\Requests;

use App\Models\Assignment;
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

        $today = now()->toDateString();

        return $student->anggotaKelas()
            ->where('kelas_id', $schedule->kelas_id)
            ->whereDate('tanggal_mulai', '<=', $today)
            ->where(function ($query) use ($today) {
                $query
                    ->whereNull('tanggal_selesai')
                    ->orWhereDate('tanggal_selesai', '>=', $today);
            })
            ->exists();
    }

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

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $jawaban = $this->input('jawaban');
            $file = $this->file('file');

            $hasJawaban = is_string($jawaban)
                ? trim($jawaban) !== ''
                : filled($jawaban);

            $hasFile = $file !== null;

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
        ];
    }
}