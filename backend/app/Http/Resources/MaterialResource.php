<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaterialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $lampiranLoaded = $this->relationLoaded('lampiran');

        $lampiran = $lampiranLoaded
            ? $this->lampiran
            : collect();

        // Fallback untuk bahan ajar lama yang belum memiliki
        // record pada tabel bahan_ajar_lampiran.
        $hasLegacyFile = !empty($this->file_path);

        $hasFile = $lampiran->isNotEmpty() || $hasLegacyFile;

        return [
            'id' => $this->id,

            'jadwal_pelajaran_id' => $this->jadwal_pelajaran_id,

            'judul' => $this->judul,

            'deskripsi' => $this->deskripsi,

            // Field lama tetap tersedia untuk kompatibilitas.
            'file_name' => $this->file_name,
            'file_mime_type' => $this->file_mime_type,
            'file_size' => $this->file_size,

            'has_file' => $hasFile,

            // Endpoint unduhan lama tetap dipertahankan.
            'download_url' => $this->when(
                $hasFile,
                fn (): string => url(
                    "/api/materials/{$this->id}/download"
                )
            ),

            // Daftar lampiran.
            'lampiran' => $this->when(
                $lampiranLoaded,
                function () use ($lampiran): array {
                    $items = $lampiran->map(
                        function ($item): array {
                            return [
                                'id' => $item->id,
                                'file_name' => $item->file_name,
                                'file_mime_type' => $item->file_mime_type,
                                'file_size' => $item->file_size,

                                'download_url' => url(
                                    "/api/materials/{$this->id}"
                                    . "/attachments/{$item->id}/download"
                                ),

                                'uploaded_by' => $item->uploaded_by,

                                'created_at' =>
                                    $item->created_at?->toISOString(),
                            ];
                        }
                    )->values()->all();

                    // Fallback file dari data lama.
                    if ($items === [] && !empty($this->file_path)) {
                        $items[] = [
                            'id' => null,

                            'file_name' => $this->file_name
                                ?: basename($this->file_path),

                            'file_mime_type' => $this->file_mime_type,
                            'file_size' => $this->file_size,

                            'download_url' => url(
                                "/api/materials/{$this->id}/download"
                            ),

                            'uploaded_by' => $this->created_by,

                            'created_at' =>
                                $this->created_at?->toISOString(),
                        ];
                    }

                    return $items;
                }
            ),

            'created_by' => $this->created_by,

            // Informasi jadwal beserta nama kelas dan mata pelajaran.
            'jadwal_pelajaran' => $this->whenLoaded(
                'jadwalPelajaran',
                function (): ?array {
                    $jadwal = $this->jadwalPelajaran;

                    if ($jadwal === null) {
                        return null;
                    }

                    $kelas = $jadwal->relationLoaded('kelas')
                        ? $jadwal->getRelation('kelas')
                        : null;

                    $mapel = $jadwal->relationLoaded('mapel')
                        ? $jadwal->getRelation('mapel')
                        : null;

                    return [
                        'id' => $jadwal->id,
                        'hari' => $jadwal->hari,
                        'jam_mulai' => $jadwal->jam_mulai,
                        'jam_selesai' => $jadwal->jam_selesai,

                        'kelas_id' => $jadwal->kelas_id,
                        'mapel_id' => $jadwal->mapel_id,
                        'guru_id' => $jadwal->guru_id,
                        'tahun_akademik_id' => $jadwal->tahun_akademik_id,

                        'kelas' => $kelas ? [
                            'id' => $kelas->id,
                            'nama' => $kelas->nama,
                            'tingkat' => $kelas->tingkat,

                            'nama_lengkap' => trim(
                                'Tingkat '
                                . $kelas->tingkat
                                . ' · '
                                . $kelas->nama
                            ),
                        ] : null,

                        'mapel' => $mapel ? [
                            'id' => $mapel->id,
                            'nama' => $mapel->nama,
                        ] : null,
                    ];
                }
            ),

            'created_by_user' => $this->whenLoaded(
                'createdBy',
                function (): ?array {
                    if ($this->createdBy === null) {
                        return null;
                    }

                    return [
                        'id' => $this->createdBy->id,
                        'name' => $this->createdBy->name,
                    ];
                }
            ),

            'created_at' => $this->created_at?->toISOString(),

            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
