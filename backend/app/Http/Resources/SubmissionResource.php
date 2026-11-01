<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;

class SubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /*
         * Lampiran submission.
         * Jika relasi belum dimuat, gunakan array kosong.
         */
        $lampiran = $this->whenLoaded('lampiran');

        $files = $lampiran instanceof MissingValue
            ? []
            : ($lampiran ?? collect())
                ->map(fn ($item) => [
                    'id' => $item->id,
                    'name' => $item->file_name,
                    'mime_type' => $item->file_mime_type,
                    'size' => $item->file_size,
                ])
                ->values()
                ->all();

        /*
         * Kompatibilitas untuk submission lama
         * yang masih menyimpan satu file langsung.
         */
        if (count($files) === 0 && $this->file_path) {
            $files[] = [
                'id' => null,
                'name' => $this->file_name,
                'mime_type' => $this->file_mime_type,
                'size' => $this->file_size,
            ];
        }

        /*
         * Data siswa hanya diambil jika relasinya dimuat.
         * MissingValue tidak boleh diakses sebagai objek siswa.
         */
        $student = $this->whenLoaded('siswa');

        $studentData = $student instanceof MissingValue
            ? null
            : ($student === null ? null : [
                'id' => $student->id,
                'nama_lengkap' => $student->nama_lengkap,
                'nis' => $student->nis,
            ]);

        return [
            'id' => $this->id,
            'assignment_id' => $this->assignment_id,
            'siswa_id' => $this->siswa_id,

            'siswa' => $studentData,

            'jawaban' => $this->jawaban,

            'file' => $this->file_path ? [
                'name' => $this->file_name,
                'mime_type' => $this->file_mime_type,
                'size' => $this->file_size,
            ] : null,

            'files' => $files,

            'submitted_at' => $this->submitted_at?->toISOString(),
            'status' => $this->status,
            'catatan' => $this->catatan,

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
