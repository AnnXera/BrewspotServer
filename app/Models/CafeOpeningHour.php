<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CafeOpeningHour extends Model
{
    public const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    public const DEFAULT_HOURS = [
        'Monday'    => ['09:00', '17:00'],
        'Tuesday'   => ['09:00', '17:00'],
        'Wednesday' => ['09:00', '17:00'],
        'Thursday'  => ['09:00', '17:00'],
        'Friday'    => ['09:00', '17:00'],
        'Saturday'  => null, // closed
        'Sunday'    => null,
    ];

    protected $fillable = [
        'cafe_id',
        'day_of_week',
        'is_closed',
        'is_24_hours',
        'open_time',
        'close_time',
    ];

    protected $casts = [
        'is_closed'   => 'boolean',
        'is_24_hours' => 'boolean',
    ];

    /** @return array<int, array<string, mixed>> Rows for DEFAULT_HOURS, ready to create. */
    public static function defaultRows(): array
    {
        return array_map(fn (string $day) => [
            'day_of_week' => $day,
            'is_closed'   => self::DEFAULT_HOURS[$day] === null,
            'is_24_hours' => false,
            'open_time'   => self::DEFAULT_HOURS[$day][0] ?? null,
            'close_time'  => self::DEFAULT_HOURS[$day][1] ?? null,
        ], self::DAYS);
    }

    public function closesAfterMidnight(): bool
    {
        return ! $this->is_closed && ! $this->is_24_hours
            && $this->open_time && $this->close_time
            && substr($this->close_time, 0, 5) <= substr($this->open_time, 0, 5);
    }

    public function cafe()
    {
        return $this->belongsTo(Cafe::class, 'cafe_id', 'cafe_id');
    }
}
