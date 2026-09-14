<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'status',
        'notes',
    ];

    protected $casts = [
        'party_size' => 'integer',
        'reservation_date' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($reservation) => $reservation->uuid = (string) Str::uuid());
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(BranchTable::class, 'table_id', 'table_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }
}
