<?php

namespace App\Http\Requests;

use App\Models\Guru;
use App\Models\JadwalPelajaran;
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

            'file' => [
                'sometimes',
                'nullable',
                'file',
                'max:10240',
                'mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx,ppt,pptx',
            ],

            'remove_file' => [
                'sometimes',
                'boolean',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $jadwalId = $this->input('jadwal_pelajaran_id');

            if (! $jadwalId) {
                return;
            }

            $jadwal = JadwalPelajaran::find($jadwalId);

            if (! $jadwal) {
                return;
            }

            $guru = Guru::where('user_id', $this->user()->id)->first();

            if (! $guru || $jadwal->guru_id !== $guru->id) {
                $validator->errors()->add(
                    'jadwal_pelajaran_id',
                    'Jadwal pelajaran tidak berada dalam kewenangan guru yang sedang login.'
                );

                return;
            }

            if ($this->has('komponen_nilai_id') && $this->filled('komponen_nilai_id')) {
                $komponen = \App\Models\KomponenNilai::find(
                    $this->input('komponen_nilai_id')
                );

                if (
                    ! $komponen ||
                    $komponen->jadwal_pelajaran_id !== $jadwal->id
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
            'file' => 'file tugas',
            'remove_file' => 'hapus file',
        ];
    }
}