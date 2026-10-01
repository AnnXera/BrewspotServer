<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CafeOpeningHourResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'day_of_week' => $this->day_of_week,
            'is_closed'   => $this->is_closed,
            'is_24_hours' => $this->is_24_hours,
            'open_time'   => $this->open_time ? substr($this->open_time, 0, 5) : null,
            'close_time'  => $this->close_time ? substr($this->close_time, 0, 5) : null,
            'closes_after_midnight' => $this->closesAfterMidnight(),
        ];
    }
}
