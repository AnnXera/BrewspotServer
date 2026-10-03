<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Reservation extends Model
{
    protected $primaryKey = 'reservation_id';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'uuid',
        'table_id',
        'created_by',
        'customer_name',
        'customer_phone',
        'customer_email',
        'party_size',
        'reservation_date',
        'reservation_end',
        'status',
        'notes',
    ];

    protected $casts = [
        'party_size' => 'integer',
        'reservation_date' => 'datetime',
        'reservation_end' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($reservation) => $reservation->uuid = (string) Str::uuid());
    }

    // Includes removed tables so history still shows where the booking was.
    public function table(): BelongsTo
    {
        return $this->belongsTo(BranchTable::class, 'table_id', 'table_id')->withTrashed();
    }

    /** Reservations that currently hold their table (pending, confirmed, seated). */
    public function scopeBlocking(Builder $query): Builder
    {
        return $query->whereIn('status', config('floor_plan.blocking_reservation_statuses'));
    }

    /** Windows that intersect [$start, $end). Back-to-back bookings do not overlap. */
    public function scopeOverlapping(Builder $query, Carbon $start, Carbon $end): Builder
    {
        return $query->where('reservation_date', '<', $end)->where('reservation_end', '>', $start);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }
}
