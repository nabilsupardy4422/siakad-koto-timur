<?php

namespace App\Http\Requests;

use App\Models\KomponenNilai;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreGradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role?->code === 'GURU';
    }

    public function rules(): array
    {
        return [
            'komponen_nilai_id' => [
                'required',
                'integer',
                'exists:komponen_nilai,id',
            ],
            'siswa_id' => [
                'required',
                'integer',
                'exists:siswa,id',
            ],
            'nilai' => [
                'required',
                'numeric',
            ],
            'catatan' => [
                'nullable',
                'string',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (
                ! $this->filled('komponen_nilai_id') ||
                ! $this->filled('siswa_id')
            ) {
                return;
            }

            $component = KomponenNilai::query()
                ->with([
                    'jadwalPelajaran.kelas',
                ])
                ->find($this->integer('komponen_nilai_id'));

            if ($component === null) {
                return;
            }

            $schedule = $component->jadwalPelajaran;

            if ($schedule === null || $schedule->kelas === null) {
                return;
            }

            $studentBelongsToClass = $schedule->kelas
                ->anggotaKelas()
                ->where('siswa_id', $this->integer('siswa_id'))
                ->whereNull('tanggal_selesai')
                ->exists();

            if (! $studentBelongsToClass) {
                $validator->errors()->add(
                    'siswa_id',
                    'Siswa tidak terdaftar sebagai anggota aktif kelas pada jadwal ini.'
                );
            }

            $duplicate = $component->nilai()
                ->where('siswa_id', $this->integer('siswa_id'))
                ->exists();

            if ($duplicate) {
                $validator->errors()->add(
                    'siswa_id',
                    'Nilai untuk siswa dan komponen ini sudah ada.'
                );
            }
        });
    }
}