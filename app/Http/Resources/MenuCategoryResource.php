<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MenuCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'         => $this->uuid,
            'name'         => $this->name,
            'is_available' => $this->is_available,
            'picture'      => $this->picture ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->picture) : null,
            'cafe_uuid'    => $this->cafe->uuid ?? null,
            'created_at'   => $this->created_at?->toISOString(),
            'updated_at'   => $this->updated_at?->toISOString(),
        ];
    }
}