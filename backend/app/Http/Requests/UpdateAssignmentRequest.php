<?php

namespace App\Http\Requests;

use App\Models\JadwalPelajaran;
use App\Models\KomponenNilai;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $assignment = $this->route('assignment');
        return $assignment instanceof \App\Models\Assignment && $this->user() !== null
            && \App\Support\AssignmentTeachingAccess::teacherCanAccess($this->user(), $assignment->jadwalPelajaran);
    }

    public function rules(): array
    {
        return [
            'jadwal_pelajaran_id' => [
                'sometimes',
                'integer',
                'exists:jadwal_pelajaran,id',
            ],

            'komponen_nilai_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:komponen_nilai,id',
            ],

            'judul' => [
                'sometimes',
                'string',
                'max:255',
            ],

            'deskripsi' => [
                'sometimes',
                'nullable',
                'string',
            ],

            'deadline' => [
                'sometimes',
                'date',
            ],

            'files' => [
                'sometimes',
                'nullable',
                'array',
                'max:10',
            ],

            'files.*' => [
                'bail',
                'file',
                'max:10240',
                'mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx,ppt,pptx',
                'extensions:jpg,jpeg,png,pdf,doc,docx,xls,xlsx,ppt,pptx',
            ],

            'remove_attachment_ids' => [
                'sometimes',
                'array',
                'max:10',
            ],

            'remove_attachment_ids.*' => [
                'required',
                'integer',
                'distinct',
                'min:1',
            ],

            // Kompatibilitas dengan frontend lama.
            'remove_file' => [
                'sometimes',
                'boolean',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) return;
            $assignment = $this->route('assignment');
            if (! $assignment instanceof \App\Models\Assignment) return;
            $jadwalId = $this->input('jadwal_pelajaran_id', $assignment->jadwal_pelajaran_id);
            $componentId = $this->has('komponen_nilai_id') ? $this->input('komponen_nilai_id') : $assignment->komponen_nilai_id;
            $jadwal = JadwalPelajaran::find($jadwalId);
            $guru = $this->user()?->guru;
            if (! $jadwal || ! $guru || (int) $jadwal->guru_id !== (int) $guru->id) {
                $validator->errors()->add('jadwal_pelajaran_id', 'Jadwal pelajaran tidak berada dalam kewenangan guru.');
                return;
            }
            if ($componentId !== null && $componentId !== '' && ! KomponenNilai::query()->whereKey($componentId)->where('jadwal_pelajaran_id', $jadwalId)->exists()) {
                $validator->errors()->add('komponen_nilai_id', 'Komponen nilai harus berasal dari jadwal pelajaran yang sama.');
            }
        });
    }

    public function attributes(): array
    {
        return [
            'jadwal_pelajaran_id' => 'jadwal pelajaran',
            'komponen_nilai_id' => 'komponen nilai',
            'judul' => 'judul tugas',
            'deskripsi' => 'deskripsi',
            'deadline' => 'deadline',
            'files' => 'lampiran tugas',
            'files.*' => 'file lampiran',
            'remove_attachment_ids' => 'daftar lampiran yang dihapus',
            'remove_attachment_ids.*' => 'ID lampiran',
            'remove_file' => 'hapus file',
        ];
    }
}
