<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'jadwal_pelajaran_id' => [
                'sometimes',
                'integer',
                'exists:jadwal_pelajaran,id',
            ],

            'judul' => [
                'sometimes',
                'string',
                'max:255',
            ],

            'deskripsi' => [
                'nullable',
                'string',
            ],

            // Tetap mendukung upload satu file dari frontend lama.
            'file' => [
                'sometimes',
                'nullable',
                'file',
                'max:10240',
            ],

            // Mendukung penambahan beberapa lampiran saat edit.
            'files' => [
                'sometimes',
                'array',
            ],

            'files.*' => [
                'file',
                'max:10240',
            ],

            // Dipertahankan untuk kompatibilitas dengan API lama.
            'remove_file' => [
                'sometimes',
                'boolean',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'jadwal_pelajaran_id.integer' =>
                'Jadwal mengajar harus berupa ID yang valid.',

            'jadwal_pelajaran_id.exists' =>
                'Jadwal mengajar yang dipilih tidak valid.',

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

            'remove_file.boolean' =>
                'Pilihan penghapusan lampiran tidak valid.',
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
            'remove_file' => 'hapus file lama',
        ];
    }
}
