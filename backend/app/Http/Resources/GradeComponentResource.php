<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GradeComponentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'jadwal_pelajaran_id' => $this->jadwal_pelajaran_id,

            'nama' => $this->nama,

            'bobot' => $this->bobot,

            'jadwal_pelajaran' => $this->whenLoaded(
                'jadwalPelajaran',
                function (): array {
                    return [
                        'id' => $this->jadwalPelajaran->id,

                        'tahun_akademik_id' =>
                            $this->jadwalPelajaran->tahun_akademik_id,

                        'kelas_id' =>
                            $this->jadwalPelajaran->kelas_id,

                        'mapel_id' =>
                            $this->jadwalPelajaran->mapel_id,

                        'guru_id' =>
                            $this->jadwalPelajaran->guru_id,

                        'hari' =>
                            $this->jadwalPelajaran->hari,

                        'jam_mulai' =>
                            $this->jadwalPelajaran->jam_mulai,

                        'jam_selesai' =>
                            $this->jadwalPelajaran->jam_selesai,
                    ];
                }
            ),

            'created_at' => $this->created_at?->toISOString(),

            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}