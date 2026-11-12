<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Cafe extends Model
{

    use SoftDeletes;

    protected $primaryKey = 'cafe_id';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'uuid',
        'user_id',
        'cafe_name',
        'low_stock_threshold_percent',
    ];

    protected $casts = [
        'low_stock_threshold_percent' => 'integer',
    ];

    public function lowStockThresholdPercent(): ?int
    {
        $val = $this->low_stock_threshold_percent;

        if ($val === null || (int) $val <= 0) {
            return null;
        }

        return min(99, max(1, (int) $val));
    }

    protected static function booted(): void
    {
        static::creating(fn ($cafe) => $cafe->uuid = (string) Str::uuid());

        static::created(fn ($cafe) => $cafe->ensureOpeningHours());
    }

    public function ensureOpeningHours(): void
    {
        if ($this->openingHours()->exists()) {
            return;
        }

        $this->openingHours()->createMany(CafeOpeningHour::defaultRows());
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CafeDocument::class, 'cafe_id', 'cafe_id');
    }

    public function branches(): HasMany
    {
        return $this->hasMany(CafeBranch::class, 'cafe_id', 'cafe_id');
    }

    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'cafe_id', 'cafe_id');
    }

    public function openingHours(): HasMany
    {
        return $this->hasMany(CafeOpeningHour::class, 'cafe_id', 'cafe_id');
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(Ingredient::class, 'cafe_id', 'cafe_id');
    }
}