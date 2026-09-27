<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'tahun_akademik_id' => $this->tahun_akademik_id,
            'kelas_id' => $this->kelas_id,
            'mapel_id' => $this->mapel_id,
            'guru_id' => $this->guru_id,

            'hari' => $this->hari,
            'jam_mulai' => $this->jam_mulai,
            'jam_selesai' => $this->jam_selesai,

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

            'kelas' => $this->whenLoaded(
                'kelas',
                function (): array {
                    return [
                        'id' => $this->kelas->id,
                        'nama' => $this->kelas->nama,
                        'tingkat' => $this->kelas->tingkat,
                    ];
                }
            ),

            'mapel' => $this->whenLoaded(
                'mapel',
                function (): array {
                    return [
                        'id' => $this->mapel->id,
                        'kode' => $this->mapel->kode,
                        'nama' => $this->mapel->nama,
                        'kkm' => $this->mapel->kkm,
                    ];
                }
            ),

            'guru' => $this->whenLoaded(
                'guru',
                function (): array {
                    return [
                        'id' => $this->guru->id,
                        'nip' => $this->guru->nip,
                        'nama_lengkap' => $this->guru->nama_lengkap,
                    ];
                }
            ),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}