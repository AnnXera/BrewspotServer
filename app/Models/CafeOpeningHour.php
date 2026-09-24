<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CafeOpeningHour extends Model
{
    protected $fillable = [
        'cafe_id',
        'day_of_week',
        'is_closed',
        'open_time',
        'close_time',
    ];

    protected $casts = [
        'is_closed' => 'boolean',
    ];

    public function cafe()
    {
        return $this->belongsTo(Cafe::class, 'cafe_id', 'cafe_id');
    }
}
