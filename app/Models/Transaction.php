<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

class Transaction extends Model
{
    protected $primaryKey = 'transaction_id';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'uuid',
        'branch_id',
        'staff_id',
        'total_amount',
        'walk_in_note',
        'status',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($transaction) => $transaction->uuid = (string) Str::uuid());
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(CafeBranch::class, 'branch_id', 'branch_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(CafeStaff::class, 'staff_id', 'staff_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransactionItem::class, 'transaction_id', 'transaction_id');
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }
}
