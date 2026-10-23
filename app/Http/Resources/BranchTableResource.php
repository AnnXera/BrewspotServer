<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class BranchTableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $upcoming = $this->relationLoaded('upcomingReservation') ? $this->upcomingReservation : null;
        $now = Carbon::now();

        $effectiveStatus = $this->status;
        $rangeLabel = null;
        $rangeStatusText = null;
        $minutesUntil = null;
        $isCurrentlyBooked = false;

        if ($upcoming && $upcoming->reservation_date && $upcoming->reservation_end) {
            $rangeLabel = $upcoming->reservation_date->format('g:i A') . ' – ' . $upcoming->reservation_end->format('g:i A');
            $isCurrentlyBooked = $now->between($upcoming->reservation_date, $upcoming->reservation_end);

            if ($isCurrentlyBooked) {
                if ($upcoming->status === 'seated') {
                    $effectiveStatus = 'occupied';
                    $rangeStatusText = "Seated ({$rangeLabel})";
                } elseif (in_array($upcoming->status, ['confirmed', 'pending'])) {
                    $effectiveStatus = 'reserved';
                    $rangeStatusText = "Reserved ({$rangeLabel})";
                }
            } else {
                $minutes = (int) $now->diffInMinutes($upcoming->reservation_date, false);
                $minutesUntil = max(0, $minutes);
                if ($minutesUntil > 0 && $minutesUntil <= 60) {
                    $rangeStatusText = "In {$minutesUntil}m ({$rangeLabel})";
                } else {
                    $rangeStatusText = "Booked {$rangeLabel}";
                }
            }
        }

        return [
            'uuid'       => $this->uuid,
            'table_name' => $this->table_name,
            'capacity'   => $this->capacity,
            'asset_key'  => $this->asset_key,
            'x_location' => (float) $this->x_location,
            'y_location' => (float) $this->y_location,
            'rotation'   => (float) $this->rotation,
            'status'     => $effectiveStatus,
            'raw_status' => $this->status,
            'shared'     => $this->isShared(),
            'range_label' => $rangeLabel,
            'range_status_text' => $rangeStatusText,
            'is_currently_booked' => $isCurrentlyBooked,
            // Register only: seats of a shared table that are booked or occupied right now.
            'seats_taken' => $this->when($this->isShared() && $this->relationLoaded('upcomingReservation'), fn () => (int) ($this->getAttribute('seats_taken') ?? 0)),
            // Only set on the register's floor plan: the next booking that holds this table.
            'upcoming_reservation' => $this->when($this->relationLoaded('upcomingReservation'), fn () => $upcoming ? [
                'uuid'             => $upcoming->uuid,
                'customer_name'    => $upcoming->customer_name,
                'customer_phone'   => $upcoming->customer_phone,
                'party_size'       => $upcoming->party_size,
                'reservation_date' => $upcoming->reservation_date?->toISOString(),
                'reservation_end'  => $upcoming->reservation_end?->toISOString(),
                'status'           => $upcoming->status,
                'notes'            => $upcoming->notes,
                'is_current'       => $isCurrentlyBooked,
                'range_label'      => $rangeLabel,
                'range_status_text' => $rangeStatusText,
                'minutes_until'    => $minutesUntil,
            ] : null),
        ];
    }
}
