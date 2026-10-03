<?php

namespace App\Http\Requests;

use App\Models\Guru;
use App\Models\JadwalPelajaran;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $guru = Guru::where('user_id', $user->id)->first();

        if ($guru === null) {
            return false;
        }

        $jadwalId = $this->input('jadwal_pelajaran_id');

        if (! is_numeric($jadwalId)) {
            return true;
        }

        $jadwal = JadwalPelajaran::find($jadwalId);

        if ($jadwal === null) {
            return true;
        }

        return $jadwal->guru_id === $guru->id;
    }

    public function rules(): array
    {
        return [
            'jadwal_pelajaran_id' => [
                'required',
                'integer',
                'exists:jadwal_pelajaran,id',
            ],

            'komponen_nilai_id' => [
                'nullable',
                'integer',
                'exists:komponen_nilai,id',
            ],

            'judul' => [
                'required',
                'string',
                'max:255',
            ],

            'deskripsi' => [
                'nullable',
                'string',
            ],

            'deadline' => [
                'required',
                'date',
            ],

            'file' => [
                'nullable',
                'file',
                'max:10240',
                'mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx,ppt,pptx',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $jadwal = JadwalPelajaran::find(
                $this->input('jadwal_pelajaran_id')
            );

            if (! $jadwal) {
                return;
            }

            if ($this->filled('komponen_nilai_id')) {
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
        ];
    }
}