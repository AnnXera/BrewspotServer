<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'is_sold_out',
    ];

    protected $casts = [
        'date' => 'date',
        'expected_servings' => 'integer',
        'servings_sold' => 'integer',
        'spoilage_qty' => 'integer',
        'is_sold_out' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($serving) => $serving->uuid = (string) Str::uuid());
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'men_item_id', 'men_item_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(CafeBranch::class, 'branch_id', 'branch_id');
    }
}
