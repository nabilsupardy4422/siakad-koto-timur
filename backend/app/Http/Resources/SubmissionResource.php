<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubmissionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'assignment_id' => $this->assignment_id,

            'siswa_id' => $this->siswa_id,

            'jawaban' => $this->jawaban,

            'file' => $this->file_path
                ? [
                    'name' => $this->file_name,
                    'mime_type' => $this->file_mime_type,
                    'size' => $this->file_size,
                ]
                : null,

            'submitted_at' => $this->submitted_at?->toISOString(),

            'status' => $this->status,

            'catatan' => $this->catatan,

            'created_at' => $this->created_at?->toISOString(),

            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}