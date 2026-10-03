<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A cafe's ingredient list. Names are unique per cafe ignoring case and
 * spacing (normalized_name), and recipes must use the ingredient's unit.
 */
class Ingredient extends Model
{
    protected $primaryKey = 'ingredient_id';

    // Mirrors INGREDIENT_UNITS in the client (app/utils/constants.ts).
    public const UNITS = [
        'tbsp', 'tsp', 'ml', 'l', 'g', 'kg', 'pumps', 'shots', 'cups', 'pieces',
        'oz', 'lb', 'slice', 'stick', 'pint', 'to taste', 'as needed', 'dash', 'pinch',
    ];

    // No real amount — recorded, but left out of usage totals.
    public const UNMEASURED_UNITS = ['to taste', 'as needed', 'dash', 'pinch'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'uuid',
        'cafe_id',
        'name',
        'normalized_name',
        'unit',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($ingredient) => $ingredient->uuid = (string) Str::uuid());

        static::saving(function ($ingredient) {
            $ingredient->name            = self::tidy($ingredient->name);
            $ingredient->normalized_name = self::normalize($ingredient->name);
        });
    }

    /** Trims and collapses inner spaces: "  Oat   Milk " → "Oat Milk". */
    public static function tidy(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', $name));
    }

    /** The duplicate-check key: "Oat  MILK" → "oat milk". */
    public static function normalize(string $name): string
    {
        return mb_strtolower(self::tidy($name));
    }

    public function isMeasured(): bool
    {
        return ! in_array($this->unit, self::UNMEASURED_UNITS, true);
    }

    public function cafe(): BelongsTo
    {
        return $this->belongsTo(Cafe::class, 'cafe_id', 'cafe_id');
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(MenuRecipe::class, 'ingredient_id', 'ingredient_id');
    }
}
