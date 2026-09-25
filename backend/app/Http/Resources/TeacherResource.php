<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeacherResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'nip' => $this->nip,
            'nama_lengkap' => $this->nama_lengkap,
            'jenis_kelamin' => $this->jenis_kelamin,
            'no_telepon' => $this->no_telepon,
            'alamat' => $this->alamat,

            'user' => $this->whenLoaded('user', function (): array {
                return [
                    'id' => $this->user->id,
                    'username' => $this->user->username,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                    'account_status' => $this->user->account_status,
                ];
            }),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}