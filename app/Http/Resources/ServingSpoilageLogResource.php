<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServingSpoilageLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $item  = $this->serving?->menuItem;
        $staff = $this->staff?->user;

        return [
            'uuid'         => $this->uuid,
            'serving_uuid' => $this->serving?->uuid,
            'menu_item'    => $item ? [
                'uuid'      => $item->uuid,
                'menu_name' => $item->menu_name,
            ] : null,
            'quantity'     => $this->quantity,
            'reason'       => $this->reason,
            'logged_by'    => $staff ? trim("{$staff->firstname} {$staff->lastname}") : null,
            'device_name'  => $this->device?->name,
            'voided_at'    => $this->voided_at?->toISOString(),
            'voided_by'    => $this->voidedBy ? trim("{$this->voidedBy->firstname} {$this->voidedBy->lastname}") : null,
            'created_at'   => $this->created_at?->toISOString(),
        ];
    }
}
