<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'name' => $this->name,
            'email' => $this->email,
            'role_id' => $this->role_id,

            'role' => $this->whenLoaded('role', function (): array {
                return [
                    'id' => $this->role->id,
                    'code' => $this->role->code,
                    'name' => $this->role->name,
                ];
            }),

            'academic_profile' => $this->when(
                $this->resource->relationLoaded('guru') && $this->resource->relationLoaded('siswa'),
                function (): array {
                    $code = $this->role?->code;
                    $profile = $code === 'GURU' ? $this->guru : ($code === 'SISWA' ? $this->siswa : null);
                    $opposite = $code === 'GURU' ? $this->siswa : ($code === 'SISWA' ? $this->guru : ($this->guru ?? $this->siswa));
                    return ['status' => $opposite ? 'conflict' : (! in_array($code, ['GURU', 'SISWA'], true) ? 'not_required' : ($profile ? 'linked' : 'unlinked')),
                        'id' => $profile?->id];
                }
            ),
            'activation_status' => $this->when(array_key_exists('code_state', $this->resource->getAttributes()), fn () => [
                'state' => $this->code_state,
                'expires_at' => $this->code_state !== 'not_applicable' && $this->code_expires_at ? \Carbon\Carbon::parse($this->code_expires_at, config('app.timezone'))->toIso8601String() : null,
            ]),
            'account_status' => $this->account_status,
            // Akun impor akan bernilai true sampai aktivasi berhasil.
            // Tidak ada kode aktivasi ataupun password di resource ini.
            'requires_activation' => (bool) $this->requires_activation,
            'activated_at' => $this->activated_at?->toIso8601String(),
            'last_login_at' => $this->last_login_at,
            'google_linked' => $this->google_id !== null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
