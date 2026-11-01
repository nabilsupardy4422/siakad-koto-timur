<?php

namespace App\Http\Requests;

use App\Models\Guru;
use App\Models\JadwalPelajaran;
use App\Models\KomponenNilai;
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

        return (int) $jadwal->guru_id === (int) $guru->id;
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

            // Kompatibilitas untuk unggahan satu berkas.
            'file' => [
                'nullable',
                'file',
                'max:10240',
                'mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx,ppt,pptx',
            ],

            // Format unggahan beberapa berkas.
            'files' => [
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
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $jadwalId = $this->input('jadwal_pelajaran_id');

            $jadwal = JadwalPelajaran::find($jadwalId);

            if (! $jadwal) {
                return;
            }

            // Komponen nilai harus berasal dari jadwal yang sama.
            if ($this->filled('komponen_nilai_id')) {
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

            // Gunakan salah satu format unggahan untuk menghindari
            // lampiran ganda yang membingungkan pada respons API.
            if (
                $this->hasFile('file')
                && $this->hasFile('files')
                && count((array) $this->file('files')) > 0
            ) {
                $validator->errors()->add(
                    'files',
                    'Gunakan file atau files, jangan keduanya sekaligus.'
                );
            }
        });
    }
}