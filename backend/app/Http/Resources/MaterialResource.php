<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaterialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'jadwal_pelajaran_id' => $this->jadwal_pelajaran_id,

            'judul' => $this->judul,

            'deskripsi' => $this->deskripsi,

            'file_name' => $this->file_name,

            'file_mime_type' => $this->file_mime_type,

            'file_size' => $this->file_size,

            'has_file' => !empty($this->file_path),

            'download_url' => $this->when(
                !empty($this->file_path),
                fn (): string => url(
                    "/api/materials/{$this->id}/download"
                )
            ),

            'created_by' => $this->created_by,

            'jadwal_pelajaran' => $this->whenLoaded(
                'jadwalPelajaran',
                function (): array {
                    return [
                        'id' => $this->jadwalPelajaran->id,
                        'hari' => $this->jadwalPelajaran->hari,
                        'jam_mulai' => $this->jadwalPelajaran->jam_mulai,
                        'jam_selesai' => $this->jadwalPelajaran->jam_selesai,
                        'kelas_id' => $this->jadwalPelajaran->kelas_id,
                        'mapel_id' => $this->jadwalPelajaran->mapel_id,
                        'guru_id' => $this->jadwalPelajaran->guru_id,
                        'tahun_akademik_id' =>
                            $this->jadwalPelajaran->tahun_akademik_id,
                    ];
                }
            ),

            'created_by_user' => $this->whenLoaded(
                'createdBy',
                function (): array {
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