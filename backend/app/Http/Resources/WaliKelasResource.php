<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WaliKelasResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'guru_id' => $this->guru_id,
            'kelas_id' => $this->kelas_id,
            'tahun_akademik_id' => $this->tahun_akademik_id,

            'tanggal_mulai' => $this->tanggal_mulai?->toDateString(),
            'tanggal_selesai' => $this->tanggal_selesai?->toDateString(),

            'guru' => $this->whenLoaded('guru', function (): array {
                return [
                    'id' => $this->guru->id,
                    'nip' => $this->guru->nip,
                    'nama_lengkap' => $this->guru->nama_lengkap,
                ];
            }),

            'kelas' => $this->whenLoaded('kelas', function (): array {
                return [
                    'id' => $this->kelas->id,
                    'nama' => $this->kelas->nama,
                    'tingkat' => $this->kelas->tingkat,
                ];
            }),

            'tahun_akademik' => $this->whenLoaded(
                'tahunAkademik',
                function (): array {
                    return [
                        'id' => $this->tahunAkademik->id,
                        'tahun_mulai' => $this->tahunAkademik->tahun_mulai,
                        'tahun_selesai' => $this->tahunAkademik->tahun_selesai,
                        'semester' => $this->tahunAkademik->semester,
                    ];
                }
            ),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}