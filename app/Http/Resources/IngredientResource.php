<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IngredientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'        => $this->uuid,
            'name'        => $this->name,
            'unit'        => $this->unit,
            'is_measured' => $this->isMeasured(),
            'is_active'   => $this->is_active,
            // Number of menu items and add-ons using it.
            'used_in'     => $this->whenCounted('recipes', fn ($items) => $items + ($this->addon_recipes_count ?? 0)),
            // Their names, menu items then add-ons, each A to Z. An item with
            // sizes has a row per size, so names are listed once.
            'used_in_names' => $this->when(
                $this->relationLoaded('recipes') && $this->relationLoaded('addonRecipes'),
                fn () => [
                    ...$this->recipes->pluck('item.menu_name')->filter()->unique()->sort()->values(),
                    ...$this->addonRecipes->pluck('addon.name')->filter()->unique()->sort()->values(),
                ]
            ),
        ];
    }
}
