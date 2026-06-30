<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Subscription extends Model
{
    protected $primaryKey = 'sub_id';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'uuid',
        'user_id',
        'sub_plan_id',
        'start_date',
        'end_date',
        'status',
        'cancel_at_period_end',
    ];

    protected $casts = [
        'start_date'            => 'datetime',
        'end_date'               => 'datetime',
        'cancel_at_period_end'   => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($sub) => $sub->uuid = (string) Str::uuid());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'sub_plan_id', 'sub_plan_id');
    }
}