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

            'account_status' => $this->account_status,
            'last_login_at' => $this->last_login_at,
            'google_linked' => $this->google_id !== null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
