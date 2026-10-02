<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FloorPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'            => $this->uuid,
            'floorplan_name'  => $this->floorplan_name,
            'canvas_width'    => (float) $this->canvas_width,
            'canvas_height'   => (float) $this->canvas_height,
            'boundary_points' => $this->boundary_points,
            'is_active'       => $this->is_active,
            'tables_count'    => $this->whenCounted('tables'),
            'tables'          => BranchTableResource::collection($this->whenLoaded('tables')),
            'elements'        => FloorPlanElementResource::collection($this->whenLoaded('elements')),
            'created_at'      => $this->created_at?->toISOString(),
            'updated_at'      => $this->updated_at?->toISOString(),
        ];
    }
}
