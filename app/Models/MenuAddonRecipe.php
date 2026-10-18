<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MenuAddonRecipe extends Model
{
    protected $primaryKey = 'addon_recipe_id';

    protected $fillable = [
        'uuid',
        'addon_id',
        'ingredient_id',
        'ingredient_name', // mirrors ingredients.name
        'quantity',
        'unit',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($recipe) => $recipe->uuid = (string) Str::uuid());
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'ingredient_id', 'ingredient_id');
    }

    public function addon(): BelongsTo
    {
        return $this->belongsTo(MenuAddon::class, 'addon_id', 'addon_id');
    }
}
