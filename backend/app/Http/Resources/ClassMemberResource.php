<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClassMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kelas_id' => $this->kelas_id,
            'siswa_id' => $this->siswa_id,
            'has_conflict' => $this->when($request->user()?->role?->code === 'TU' && isset($this->has_conflict), fn()=> (bool)$this->has_conflict),

            'siswa' => $this->whenLoaded('siswa', function () use ($request) {
                $private = $request->user()?->role?->code === 'TU' || (int) $request->user()?->id === (int) $this->siswa->user_id;
                return [
                    'id' => $this->siswa->id,
                    'nisn' => $this->siswa->nisn,
                    'nis' => $this->siswa->nis,
                    'nipd' => $this->when($private, fn () => $this->siswa->nipd),
                    'nama_lengkap' => $this->siswa->nama_lengkap,
                    'user' => $this->siswa->user
                        ? [
                            'username' => $this->when($private, fn () => $this->siswa->user->username),
                            'name' => $this->siswa->user->name,
                        ]
                        : null,
                ];
            }),

            'tanggal_mulai' => $this->tanggal_mulai?->toDateString(),
            'tanggal_selesai' => $this->tanggal_selesai?->toDateString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
