<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class InventoryServing extends Model
{
    protected $primaryKey = 'servings_id';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'uuid',
        'men_item_id',
        'branch_id',
        'date',
        'expected_servings',
        'servings_sold',
        'spoilage_qty',
        'servings_refunded',
        'is_sold_out',
    ];

    protected $casts = [
        'date' => 'date',
        'expected_servings' => 'integer',
        'servings_sold' => 'integer',
        'spoilage_qty' => 'integer',
        'servings_refunded' => 'integer',
        'is_sold_out' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($serving) => $serving->uuid = (string) Str::uuid());
    }

    /** Servings gone for the day: sold, spoiled, or made and then refunded. */
    public function used(): int
    {
        return $this->servings_sold + $this->spoilage_qty + $this->servings_refunded;
    }

    public function remaining(): int
    {
        return max(0, $this->expected_servings - $this->used());
    }

    /** Every expected serving is used. Days without a target never run out. */
    public function isUsedUp(): bool
    {
        return $this->expected_servings > 0 && $this->used() >= $this->expected_servings;
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'men_item_id', 'men_item_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(CafeBranch::class, 'branch_id', 'branch_id');
    }

    public function spoilageLogs(): HasMany
    {
        return $this->hasMany(ServingSpoilageLog::class, 'servings_id', 'servings_id');
    }
}
