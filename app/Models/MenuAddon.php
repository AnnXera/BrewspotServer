<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * An extra a customer can add to a drink (Oreo crumbles +20). Defined once per
 * cafe and offered on the menu items it's linked to.
 */
class MenuAddon extends Model
{
    use SoftDeletes;

    protected $primaryKey = 'addon_id';

    public const MAX_QUANTITY_LIMIT = 10;

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'uuid',
        'cafe_id',
        'name',
        'price',
        'max_quantity',
        'is_available',
    ];

    protected $casts = [
        'price'        => 'decimal:2',
        'max_quantity' => 'integer',
        'is_available' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($addon) => $addon->uuid = (string) Str::uuid());
    }

    public function cafe(): BelongsTo
    {
        return $this->belongsTo(Cafe::class, 'cafe_id', 'cafe_id');
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(MenuAddonRecipe::class, 'addon_id', 'addon_id');
    }

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(MenuItem::class, 'menu_item_addons', 'addon_id', 'men_item_id')->withTimestamps();
    }
}
