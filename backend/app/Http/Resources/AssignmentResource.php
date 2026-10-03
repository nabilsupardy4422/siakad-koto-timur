<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'jadwal_pelajaran_id' => $this->jadwal_pelajaran_id,
            'komponen_nilai_id' => $this->komponen_nilai_id,

            'judul' => $this->judul,
            'deskripsi' => $this->deskripsi,
            'deadline' => $this->deadline?->toISOString(),

            'file' => $this->when(
                $this->file_path !== null,
                [
                    'name' => $this->file_name,
                    'mime_type' => $this->file_mime_type,
                    'size' => $this->file_size,
                    'download_url' => route(
                        'assignments.download',
                        $this->resource
                    ),
                ]
            ),

            'created_by' => $this->created_by,

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}