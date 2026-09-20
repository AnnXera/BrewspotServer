<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MenuItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'          => $this->uuid,
            'category_uuid' => $this->category->uuid ?? null,
            'menu_name'     => $this->menu_name,
            'description'   => $this->description,
            'base_price'    => $this->base_price,
            'is_available'  => $this->is_available,
            'picture'       => $this->picture ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->picture) : null,
            'recipes'       => MenuRecipeResource::collection($this->whenLoaded('recipes')),
            'created_at'    => $this->created_at?->toISOString(),
            'updated_at'    => $this->updated_at?->toISOString(),
        ];
    }
}
