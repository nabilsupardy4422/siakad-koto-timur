<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AcademicYearResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tahun_mulai' => $this->tahun_mulai,
            'tahun_selesai' => $this->tahun_selesai,
            'semester' => $this->semester,
            'is_active' => $this->is_active,
            'identity_locked' => $this->when(
                $request->user()?->role?->code === 'TU' && array_key_exists('kelas_exists', $this->resource->getAttributes()),
                fn () => (bool) ($this->is_active || $this->kelas_exists || $this->wali_kelas_exists || $this->jadwal_pelajaran_exists || $this->rapor_exists),
            ),
        ];
    }
}
