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
            // Number of menu items using it (one recipe row per item).
            'used_in'     => $this->whenCounted('recipes'),
        ];
    }
}
