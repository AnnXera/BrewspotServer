<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

class Payment extends Model
{
    protected $primaryKey = 'payments_id';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'uuid',
        'user_id',
        'payable_type',
        'payable_id',
        'amount',
        'amount_tendered',
        'amount_change',
        'payment_method_type',
        'gateway_transaction_id',
        'status',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($payment) => $payment->uuid = (string) Str::uuid());
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}