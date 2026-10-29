<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One size of a menu item (Small, Large) with its own price. On an item sold
 * hot and iced, price is the hot price and iced_price the iced one.
 */
class MenuItemSize extends Model
{
    protected $primaryKey = 'men_size_id';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'uuid',
        'men_item_id',
        'name',
        'price',
        'iced_price',
        'sort_order',
    ];

    protected $casts = [
        'price'      => 'decimal:2',
        'iced_price' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($size) => $size->uuid = (string) Str::uuid());
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'men_item_id', 'men_item_id');
    }

    /** Rows used only by this size (shared rows have men_size_id = null). */
    public function recipes(): HasMany
    {
        return $this->hasMany(MenuRecipe::class, 'men_size_id', 'men_size_id');
    }
}
