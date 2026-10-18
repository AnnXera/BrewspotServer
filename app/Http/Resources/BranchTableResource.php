<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchTableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $upcoming = $this->relationLoaded('upcomingReservation') ? $this->upcomingReservation : null;

        return [
            'uuid'       => $this->uuid,
            'table_name' => $this->table_name,
            'capacity'   => $this->capacity,
            'asset_key'  => $this->asset_key,
            'x_location' => (float) $this->x_location,
            'y_location' => (float) $this->y_location,
            'rotation'   => (float) $this->rotation,
            'status'     => $this->status,
            'shared'     => $this->isShared(),
            // Register only: seats of a shared table that are booked or occupied right now.
            'seats_taken' => $this->when($this->isShared() && $this->relationLoaded('upcomingReservation'), fn () => (int) ($this->getAttribute('seats_taken') ?? 0)),
            // Only set on the register's floor plan: the next booking that holds this table.
            'upcoming_reservation' => $this->when($this->relationLoaded('upcomingReservation'), fn () => $upcoming ? [
                'uuid'             => $upcoming->uuid,
                'customer_name'    => $upcoming->customer_name,
                'party_size'       => $upcoming->party_size,
                'reservation_date' => $upcoming->reservation_date?->toISOString(),
                'reservation_end'  => $upcoming->reservation_end?->toISOString(),
                'status'           => $upcoming->status,
            ] : null),
        ];
    }
}
