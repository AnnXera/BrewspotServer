<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MenuCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $itemsPreview = null;
        $remainingCount = 0;

        if ($this->relationLoaded('items')) {
            $items = $this->items;
            $itemsCount = $items->count();
            if ($itemsCount > 0) {
                $previewItems = $items->take(5);
                $itemsPreview = $previewItems->pluck('menu_name')->join(' • ');
                $remainingCount = $itemsCount > 5 ? $itemsCount - 5 : 0;
            }
        }

        return [
            'uuid'           => $this->uuid,
            'name'           => $this->name,
            'is_available'   => $this->is_available,
            'picture'        => $this->picture ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->picture) : null,
            'cafe_uuid'      => $this->cafe->uuid ?? null,
            'items_preview'  => $itemsPreview,
            'remaining_count'=> $remainingCount,
            'created_at'     => $this->created_at?->toISOString(),
            'updated_at'     => $this->updated_at?->toISOString(),
        ];
    }
}