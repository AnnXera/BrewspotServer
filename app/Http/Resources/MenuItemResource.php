<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MenuItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $recipesPreview = null;
        $remainingCount = 0;

        if ($this->relationLoaded('recipes')) {
            // Size-specific rows repeat an ingredient once per size; list it once.
            $recipes = $this->recipes->unique('ingredient_id');
            $recipesCount = $recipes->count();
            if ($recipesCount > 0) {
                $previewRecipes = $recipes->take(4);
                $recipesPreview = $previewRecipes->pluck('ingredient_name')->join(' • ');
                $remainingCount = $recipesCount > 4 ? $recipesCount - 4 : 0;
            }
        }

        return [
            'uuid'                    => $this->uuid,
            'category_uuid'           => $this->category->uuid ?? null,
            'category_name'           => $this->category->name ?? null,
            'menu_name'               => $this->menu_name,
            'description'             => $this->description,
            'base_price'              => $this->base_price,
            'is_available'            => $this->is_available,
            'picture'                 => $this->picture ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->picture) : null,
            'recipes_preview'         => $recipesPreview,
            'recipes_remaining_count' => $remainingCount,
            'recipes'                 => MenuRecipeResource::collection($this->whenLoaded('recipes')),
            // In display order; empty = one price (base_price).
            'sizes'                   => $this->whenLoaded('sizes', fn () => $this->sizes->map(fn ($size) => [
                'uuid'  => $size->uuid,
                'name'  => $size->name,
                'price' => $size->price,
            ])->values()),
            'addons'                  => $this->whenLoaded('addons', fn () => $this->addons->map(fn ($addon) => [
                'uuid'         => $addon->uuid,
                'name'         => $addon->name,
                'price'        => $addon->price,
                'is_available' => $addon->is_available,
            ])->values()),
            'created_at'              => $this->created_at?->toISOString(),
            'updated_at'              => $this->updated_at?->toISOString(),
        ];
    }
}
