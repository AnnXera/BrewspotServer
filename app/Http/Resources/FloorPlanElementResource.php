<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FloorPlanElementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'       => $this->uuid,
            'category'   => $this->category,
            'asset_key'  => $this->asset_key,
            'label'      => $this->label,
            'x_location' => (float) $this->x_location,
            'y_location' => (float) $this->y_location,
            'width'      => $this->width === null ? null : (float) $this->width,
            'height'     => $this->height === null ? null : (float) $this->height,
            'rotation'   => (float) $this->rotation,
            'z_index'    => $this->z_index,
        ];
    }
}
