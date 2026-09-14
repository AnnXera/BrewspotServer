<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MenuRecipe extends Model
{
    protected $primaryKey = 'men_recipe_id';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'uuid',
        'men_item_id',
        'ingredient_name',
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

    public function item(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'men_item_id', 'men_item_id');
    }
}
