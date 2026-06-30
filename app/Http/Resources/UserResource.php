<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'              => $this->uuid,
            'firstname'         => $this->firstname,
            'middlename'        => $this->middlename,
            'lastname'          => $this->lastname,
            'username'          => $this->username,
            'email'             => $this->email,
            'phone_number'      => $this->phone_number,
            'status'            => $this->status,
            'email_verified_at' => $this->email_verified_at?->toISOString(),
            'role'              => $this->whenLoaded('role', fn () => [
                'uuid'      => $this->role->uuid,
                'role_name' => $this->role->role_name,
            ]),
            'created_at'        => $this->created_at?->toISOString(),
        ];
    }
}