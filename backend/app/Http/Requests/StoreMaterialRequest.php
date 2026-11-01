<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'jadwal_pelajaran_id' => [
                'required',
                'integer',
                'exists:jadwal_pelajaran,id',
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

            // Kompatibilitas dengan frontend lama yang mengirim satu file.
            'file' => [
                'nullable',
                'file',
                'max:10240',
            ],

            // Mendukung beberapa lampiran dalam satu request.
            'files' => [
                'sometimes',
                'array',
            ],

            // Validasi setiap file secara individual, maksimal 10 MB.
            'files.*' => [
                'file',
                'max:10240',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'jadwal_pelajaran_id.required' =>
                'Jadwal mengajar wajib dipilih.',

            'jadwal_pelajaran_id.integer' =>
                'Jadwal mengajar harus berupa ID yang valid.',

            'jadwal_pelajaran_id.exists' =>
                'Jadwal mengajar yang dipilih tidak valid.',

            'judul.required' =>
                'Judul bahan ajar wajib diisi.',

            'judul.string' =>
                'Judul bahan ajar harus berupa teks.',

            'judul.max' =>
                'Judul bahan ajar maksimal 255 karakter.',

            'deskripsi.string' =>
                'Deskripsi bahan ajar harus berupa teks.',

            'file.file' =>
                'Lampiran harus berupa file yang valid.',

            'file.max' =>
                'Ukuran file bahan ajar maksimal 10 MB.',

            'files.array' =>
                'Daftar lampiran harus berupa kumpulan file.',

            'files.*.file' =>
                'Setiap lampiran harus berupa file yang valid.',

            'files.*.max' =>
                'Ukuran setiap lampiran maksimal 10 MB.',
        ];
    }

    public function attributes(): array
    {
        return [
            'jadwal_pelajaran_id' => 'jadwal mengajar',
            'judul' => 'judul bahan ajar',
            'deskripsi' => 'deskripsi bahan ajar',
            'file' => 'file bahan ajar',
            'files' => 'daftar lampiran',
            'files.*' => 'lampiran bahan ajar',
        ];
    }
}
