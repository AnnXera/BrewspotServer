<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class InventoryServingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $item = $this->menuItem;

        return [
            'uuid'              => $this->uuid,
            'date'              => $this->date?->toDateString(),
            'menu_item'         => $item ? [
                'uuid'          => $item->uuid,
                'menu_name'     => $item->menu_name,
                'category_uuid' => $item->category?->uuid,
                'category_name' => $item->category?->name,
                'base_price'    => $item->base_price,
                'picture'       => $item->picture ? Storage::disk('public')->url($item->picture) : null,
                'is_deleted'    => $item->trashed(),
            ] : null,
            'expected_servings' => $this->expected_servings,
            'servings_sold'     => $this->servings_sold,
            'spoilage_qty'      => $this->spoilage_qty,
            'remaining'         => max(0, $this->expected_servings - $this->servings_sold - $this->spoilage_qty),
            'is_sold_out'       => $this->is_sold_out,
            'created_at'        => $this->created_at?->toISOString(),
            'updated_at'        => $this->updated_at?->toISOString(),
        ];
    }
}
