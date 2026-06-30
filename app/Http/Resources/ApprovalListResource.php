<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApprovalListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'        => $this->uuid,
            'status'      => $this->status,
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'user'        => new UserResource($this->whenLoaded('user')),
            'cafe'        => new CafeResource($this->whenLoaded('cafe')),
            'branch'      => new CafeBranchResource($this->whenLoaded('branch')),
            'reviewer'    => $this->whenLoaded('reviewer', fn () => [
                'uuid'      => $this->reviewer->uuid,
                'firstname' => $this->reviewer->firstname,
                'lastname'  => $this->reviewer->lastname,
            ]),
            'created_at'  => $this->created_at?->toISOString(),
        ];
    }
}