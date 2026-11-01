<?php

namespace App\Http\Requests;

use App\Models\Guru;
use App\Models\JadwalPelajaran;
use App\Models\KomponenNilai;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
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
            $jadwalId = $this->input('jadwal_pelajaran_id');

            if (! $jadwalId) {
                return;
            }

            $jadwal = JadwalPelajaran::find($jadwalId);

            if (! $jadwal) {
                return;
            }

            $user = $this->user();

            if (! $user) {
                return;
            }

            $guru = Guru::where('user_id', $user->id)->first();

            if (! $guru || (int) $jadwal->guru_id !== (int) $guru->id) {
                $validator->errors()->add(
                    'jadwal_pelajaran_id',
                    'Jadwal pelajaran tidak berada dalam kewenangan guru yang sedang login.'
                );

                return;
            }

            if (
                $this->has('komponen_nilai_id')
                && $this->filled('komponen_nilai_id')
            ) {
                $komponen = KomponenNilai::find(
                    $this->input('komponen_nilai_id')
                );

                if (
                    ! $komponen
                    || (int) $komponen->jadwal_pelajaran_id
                        !== (int) $jadwal->id
                ) {
                    $validator->errors()->add(
                        'komponen_nilai_id',
                        'Komponen nilai harus berasal dari jadwal pelajaran yang sama.'
                    );
                }
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
