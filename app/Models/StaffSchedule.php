<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class StaffSchedule extends Model
{
    protected $primaryKey = 'schedule_id';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'uuid',
        'staff_id',
        'day_of_week',
        'is_day_off',
        'start_time',
        'end_time',
    ];

    protected $casts = [
        'day_of_week' => 'integer',
        'is_day_off'  => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($schedule) => $schedule->uuid = (string) Str::uuid());
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(CafeStaff::class, 'staff_id', 'staff_id');
    }
}
