<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryBranchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'          => $this->uuid,
            'is_available'  => $this->is_available,
            'branch'        => $this->whenLoaded('branch', fn () => [
                'uuid'        => $this->branch->uuid,
                'branch_name' => $this->branch->branch_name,
            ]),
            'category'      => $this->whenLoaded('category', fn () => [
                'uuid' => $this->category->uuid,
                'name' => $this->category->name,
            ]),
            'updated_at'    => $this->updated_at?->toISOString(),
        ];
    }
}