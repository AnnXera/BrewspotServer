<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Cafe extends Model
{

    use SoftDeletes;

    protected $primaryKey = 'cafe_id';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'uuid',
        'user_id',
        'cafe_name',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($cafe) => $cafe->uuid = (string) Str::uuid());

        static::created(function ($cafe) {
            $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
            foreach ($days as $day) {
                $isWeekend = in_array($day, ['Saturday', 'Sunday']);
                $cafe->openingHours()->create([
                    'day_of_week' => $day,
                    'is_closed'   => $isWeekend,
                    'open_time'   => $isWeekend ? null : '09:00:00',
                    'close_time'  => $isWeekend ? null : '17:00:00',
                ]);
            }
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CafeDocument::class, 'cafe_id', 'cafe_id');
    }

    public function branches(): HasMany
    {
        return $this->hasMany(CafeBranch::class, 'cafe_id', 'cafe_id');
    }

    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'cafe_id', 'cafe_id');
    }

    public function openingHours(): HasMany
    {
        return $this->hasMany(CafeOpeningHour::class, 'cafe_id', 'cafe_id');
    }
}