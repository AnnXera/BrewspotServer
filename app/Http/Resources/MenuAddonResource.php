<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MenuAddonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'         => $this->uuid,
            'name'         => $this->name,
            'price'        => $this->price,
            'max_quantity' => $this->max_quantity,
            'is_available' => $this->is_available,
            'recipes'      => $this->whenLoaded('recipes', fn () => $this->recipes->map(fn ($recipe) => [
                'ingredient_uuid' => $recipe->ingredient?->uuid,
                'ingredient_name' => $recipe->ingredient_name,
                'quantity'        => $recipe->quantity,
                'unit'            => $recipe->unit,
            ])->values()),
            // Menu items that offer it.
            'items'        => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'uuid'      => $item->uuid,
                'menu_name' => $item->menu_name,
            ])->sortBy('menu_name')->values()),
        ];
    }
}
