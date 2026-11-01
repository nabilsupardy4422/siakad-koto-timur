<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role?->code === 'GURU';
    }

    public function rules(): array
    {
        return [
            'jadwal_pelajaran_id' => [
                'required',
                'integer',
                'exists:jadwal_pelajaran,id',
            ],

            'tanggal' => [
                'required',
                'date_format:Y-m-d',
            ],

            'presensi' => [
                'required',
                'array',
                'min:1',
            ],

            'presensi.*.siswa_id' => [
                'required',
                'integer',
                'distinct',
                'exists:siswa,id',
            ],

            'presensi.*.status' => [
                'required',
                'string',
                Rule::in(['HADIR', 'IZIN', 'SAKIT', 'ALPA']),
            ],

            'presensi.*.catatan' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'jadwal_pelajaran_id.required' =>
                'Jadwal pelajaran wajib dipilih.',

            'tanggal.required' =>
                'Tanggal presensi wajib diisi.',

            'tanggal.date_format' =>
                'Tanggal harus menggunakan format YYYY-MM-DD.',

            'presensi.required' =>
                'Daftar presensi wajib diisi.',

            'presensi.min' =>
                'Minimal satu siswa harus disertakan.',

            'presensi.*.siswa_id.distinct' =>
                'Siswa yang sama tidak boleh dimasukkan lebih dari sekali.',

            'presensi.*.status.in' =>
                'Status harus HADIR, IZIN, SAKIT, atau ALPA.',
        ];
    }
}