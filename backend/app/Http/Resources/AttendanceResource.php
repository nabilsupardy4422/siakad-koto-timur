<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'jadwal_pelajaran_id' => $this->jadwal_pelajaran_id,
            'siswa_id' => $this->siswa_id,

            'tanggal' => $this->tanggal?->toDateString(),
            'status' => $this->status,
            'catatan' => $this->catatan,

            'jadwal_pelajaran' => $this->whenLoaded(
                'jadwalPelajaran',
                function (): array {
                    return [
                        'id' => $this->jadwalPelajaran->id,
                        'hari' => $this->jadwalPelajaran->hari,
                        'jam_mulai' => $this->jadwalPelajaran->jam_mulai,
                        'jam_selesai' => $this->jadwalPelajaran->jam_selesai,
                    ];
                }
            ),

            'siswa' => $this->whenLoaded(
                'siswa',
                function (): array {
                    return [
                        'id' => $this->siswa->id,
                        'nis' => $this->siswa->nis,
                        'nama_lengkap' => $this->siswa->nama_lengkap,
                    ];
                }
            ),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}