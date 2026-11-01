<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $schedule = $this->jadwalPelajaran;

        /*
         * Deteksi apakah lampiran sudah dimuat dari tabel
         * assignment_lampiran.
         */
        $attachmentsLoaded = $this->resource->relationLoaded('lampiran');

        $attachments = $attachmentsLoaded
            ? $this->lampiran
            : collect();

        /*
         * Format lama hanya ditampilkan apabila:
         * 1. Kolom file lama masih berisi data; dan
         * 2. Lampiran belum tercatat pada relasi lampiran.
         *
         * Ini mencegah lampiran lama muncul dua kali
         * setelah migrasi ke tabel assignment_lampiran.
         */
        $showLegacyFile = $this->file_path !== null
            && (
                ! $attachmentsLoaded
                || $attachments->isEmpty()
            );

        return [
            'id' => $this->id,

            'jadwal_pelajaran_id' => $this->jadwal_pelajaran_id,
            'komponen_nilai_id' => $this->komponen_nilai_id,

            'jadwal_pelajaran' => $this->whenLoaded(
                'jadwalPelajaran',
                fn () => [
                    'id' => $schedule?->id,
                    'semester' => $schedule?->semester,

                    'mapel' => $schedule?->mapel
                        ? [
                            'id' => $schedule->mapel->id,
                            'nama' => $schedule->mapel->nama,
                        ]
                        : null,

                    'kelas' => $schedule?->kelas
                        ? [
                            'id' => $schedule->kelas->id,
                            'nama' => $schedule->kelas->nama,
                            'tingkat' => $schedule->kelas->tingkat,
                        ]
                        : null,
                ]
            ),

            'komponen_nilai' => $this->whenLoaded(
                'komponenNilai',
                fn () => $this->komponenNilai
                    ? [
                        'id' => $this->komponenNilai->id,
                        'nama' => $this->komponenNilai->nama,
                    ]
                    : null
            ),

            'judul' => $this->judul,
            'deskripsi' => $this->deskripsi,
            'deadline' => $this->deadline?->toISOString(),

            /*
             * Format lampiran lama.
             *
             * Tetap tersedia untuk tugas lama yang belum
             * mempunyai data pada tabel assignment_lampiran.
             */
            'file' => $this->when(
                $showLegacyFile,
                fn () => [
                    'name' => $this->file_name,
                    'mime_type' => $this->file_mime_type,
                    'size' => $this->file_size,
                    'download_url' => route(
                        'assignments.download',
                        $this->resource
                    ),
                ]
            ),

            /*
             * Format baru untuk seluruh lampiran tugas.
             *
             * Frontend guru dan siswa menggunakan properti
             * files untuk menampilkan dan mengunduh lampiran.
             */
            'files' => $this->whenLoaded(
                'lampiran',
                fn () => $this->lampiran
                    ->map(fn ($attachment) => [
                        'id' => $attachment->id,
                        'name' => $attachment->file_name,
                        'mime_type' => $attachment->file_mime_type,
                        'size' => $attachment->file_size,
                        'download_url' => route(
                            'assignment-attachments.download',
                            $attachment
                        ),
                    ])
                    ->values()
                    ->all()
            ),

            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
