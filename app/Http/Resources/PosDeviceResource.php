<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PosDeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $activeUser = $this->activeStaff?->user;

        return [
            'uuid'          => $this->uuid,
            'name'          => $this->name,
            'branch_uuid'   => $this->branch?->uuid,
            'branch_name'   => $this->branch?->branch_name,
            'registered_by' => $this->registeredBy
                ? trim("{$this->registeredBy->firstname} {$this->registeredBy->lastname}")
                : null,
            'active_staff'  => $activeUser ? [
                'uuid' => $activeUser->uuid,
                'name' => trim("{$activeUser->firstname} {$activeUser->lastname}"),
            ] : null,
            'active_since'  => $this->active_since?->toISOString(),
            'last_used_at'  => $this->last_used_at?->toISOString(),
            'revoked_at'    => $this->revoked_at?->toISOString(),
            'created_at'    => $this->created_at?->toISOString(),
        ];
    }
}
