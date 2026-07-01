<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'          => $this->uuid,
            'sub_name'      => $this->sub_name,
            'price'         => $this->price,
            'max_branches'  => $this->max_branches,
            'description'   => $this->description,
            'duration_days' => $this->duration_days,
            'is_active'     => $this->is_active,
            'created_at'    => $this->created_at?->toISOString(),
            'updated_at'    => $this->updated_at?->toISOString(),
        ];
    }
}