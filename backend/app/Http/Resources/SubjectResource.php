<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $admin = $request->user()?->role?->code === 'TU';
        return [
            'id' => $this->id,
            'kode' => $this->kode,
            'nama' => $this->nama,
            'kkm' => $this->kkm,
            'schedule_count' => $this->when($admin && isset($this->jadwal_pelajaran_count), fn () => (int) $this->jadwal_pelajaran_count),
            'usage_context' => $this->when($admin && isset($this->usage_context), fn () => $this->usage_context),
            'version' => $this->when($admin, fn () => $this->resource->directoryVersion()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
