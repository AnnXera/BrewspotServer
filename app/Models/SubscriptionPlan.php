<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class SubscriptionPlan extends Model
{
    use SoftDeletes;

    protected $primaryKey = 'sub_plan_id';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'uuid',
        'sub_name',
        'price',
        'max_branches',
        'description',
        'duration_days',
        'is_active',
    ];

    protected $casts = [
        'price'     => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($plan) => $plan->uuid = (string) Str::uuid());
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'sub_plan_id', 'sub_plan_id');
    }
}