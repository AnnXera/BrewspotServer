<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $table   = $this->relationLoaded('table') ? $this->table : null;
        $creator = $this->relationLoaded('creator') ? $this->creator : null;

        $now = \Illuminate\Support\Carbon::now();
        $isCurrent = false;
        $rangeLabel = null;
        $minutesUntil = null;

        if ($this->reservation_date && $this->reservation_end) {
            $rangeLabel = $this->reservation_date->format('g:i A') . ' – ' . $this->reservation_end->format('g:i A');
            $isCurrent = $now->between($this->reservation_date, $this->reservation_end);
            $diff = (int) $now->diffInMinutes($this->reservation_date, false);
            $minutesUntil = max(0, $diff);
        }

        return [
            'uuid'             => $this->uuid,
            'table'            => $table ? [
                'uuid'            => $table->uuid,
                'table_name'      => $table->table_name,
                'capacity'        => $table->capacity,
                'removed'         => $table->trashed(),
                'floor_plan_uuid' => $table->relationLoaded('floorPlan') ? $table->floorPlan?->uuid : null,
                'floor_plan_name' => $table->relationLoaded('floorPlan') ? $table->floorPlan?->floorplan_name : null,
            ] : null,
            'customer_name'    => $this->customer_name,
            'customer_phone'   => $this->customer_phone,
            'customer_email'   => $this->customer_email,
            'party_size'       => $this->party_size,
            'reservation_date' => $this->reservation_date?->toISOString(),
            'reservation_end'  => $this->reservation_end?->toISOString(),
            'range_label'      => $rangeLabel,
            'is_current'       => $isCurrent,
            'minutes_until'    => $minutesUntil,
            'status'           => $this->status,
            'notes'            => $this->notes,
            'created_by'       => $creator ? trim("{$creator->firstname} {$creator->lastname}") : null,
            'created_at'       => $this->created_at?->toISOString(),
        ];
    }
}
