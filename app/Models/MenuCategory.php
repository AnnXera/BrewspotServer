<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MenuCategory extends Model
{
    protected $primaryKey = 'men_category_id';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'uuid',
        'cafe_id',
        'name',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($category) => $category->uuid = (string) Str::uuid());
    }

    public function cafe(): BelongsTo
    {
        return $this->belongsTo(Cafe::class, 'cafe_id', 'cafe_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'men_category_id', 'men_category_id');
    }
}