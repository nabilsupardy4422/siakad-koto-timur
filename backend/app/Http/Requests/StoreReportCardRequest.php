<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReportCardRequest extends FormRequest
{
    /**
     * Determine whether the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->role?->code === 'TU';
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'siswa_id' => [
                'required',
                'integer',
                'exists:siswa,id',
            ],

            'tahun_akademik_id' => [
                'required',
                'integer',
                'exists:tahun_akademik,id',
            ],

            'jenis_rapor' => [
                'required',
                'string',
                Rule::in([
                    'MID_SEMESTER',
                    'AKHIR_SEMESTER',
                ]),
            ],

            'file' => [
                'required',
                'file',
                'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx,ppt,pptx',
                'max:10240',
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'siswa_id.required' => 'Siswa wajib dipilih.',
            'siswa_id.exists' => 'Siswa tidak ditemukan.',

            'tahun_akademik_id.required' => 'Tahun akademik wajib dipilih.',
            'tahun_akademik_id.exists' => 'Tahun akademik tidak ditemukan.',

            'jenis_rapor.required' => 'Jenis rapor wajib dipilih.',
            'jenis_rapor.in' => 'Jenis rapor tidak valid.',

            'file.required' => 'Dokumen rapor wajib diunggah.',
            'file.file' => 'File rapor tidak valid.',
            'file.mimes' => 'Format file rapor tidak didukung.',
            'file.max' => 'Ukuran file rapor maksimal 10 MB.',
        ];
    }
}