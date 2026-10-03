<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportCardResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'student' => [
                'id' => $this->siswa?->id,
                'name' => $this->siswa?->nama_lengkap,
                'nis' => $this->siswa?->nis,
                'nisn' => $this->siswa?->nisn,
            ],

            'academic_year' => [
                'id' => $this->tahunAkademik?->id,
                'tahun_mulai' => $this->tahunAkademik?->tahun_mulai,
                'tahun_selesai' => $this->tahunAkademik?->tahun_selesai,
                'semester' => $this->tahunAkademik?->semester,
                'is_active' => $this->tahunAkademik?->is_active,
            ],

            'type' => $this->jenis_rapor,

            'file' => [
                'name' => $this->file_name,
                'mime_type' => $this->file_mime_type,
                'size' => $this->file_size,
            ],

            'uploaded_by' => [
                'id' => $this->uploadedBy?->id,
                'name' => $this->uploadedBy?->name,
            ],

            'uploaded_at' => $this->uploaded_at?->toISOString(),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}