<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeacherResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $private = $request->user()?->role?->code === 'TU' || (int) $request->user()?->id === (int) $this->user_id;
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'nip' => $this->nip,
            'nama_lengkap' => $this->nama_lengkap,
            'jenis_kelamin' => $this->jenis_kelamin,
            'no_telepon' => $this->when($private, fn () => $this->no_telepon),
            'alamat' => $this->when($private, fn () => $this->alamat),

            'user' => $this->whenLoaded('user', function () use ($request, $private): array {
                return [
                    'id' => $this->user->id,
                    'username' => $this->when($private, fn () => $this->user->username),
                    'name' => $this->user->name,
                    'email' => $this->when($private, fn () => $this->user->email),
                    'account_status' => $this->when($private, fn () => $this->user->account_status),
                    'requires_activation' => $this->when($request->user()?->role?->code === 'TU' || (int) $request->user()?->id === (int) $this->user_id, fn () => (bool) $this->user->requires_activation),
                ];
            }),

            'completeness' => $this->when($private, fn () => \App\Support\IdentityDirectory::completeness($this->resource, false)),
            'profile_version' => $this->when($request->user()?->role?->code === 'TU', fn () => \App\Services\AcademicIdentityService::version($this->resource)),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
