<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class MenuItem extends Model
{
    use SoftDeletes;

    protected $primaryKey = 'men_item_id';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'uuid',
        'men_category_id',
        'menu_name',
        'description',
        'base_price',
        'is_available',
        'picture',
    ];

    protected $casts = [
        'base_price' => 'decimal:2',
        'is_available' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($item) => $item->uuid = (string) Str::uuid());
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class, 'men_category_id', 'men_category_id');
    }

    public function branchOverrides(): HasMany
    {
        return $this->hasMany(MenuBranch::class, 'men_item_id', 'men_item_id');
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(MenuRecipe::class, 'men_item_id', 'men_item_id');
    }

    public function transactionItems(): HasMany
    {
        return $this->hasMany(TransactionItem::class, 'men_item_id', 'men_item_id');
    }

    public function inventoryServings(): HasMany
    {
        return $this->hasMany(InventoryServing::class, 'men_item_id', 'men_item_id');
    }
}
