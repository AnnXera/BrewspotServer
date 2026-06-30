<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Cafe extends Model
{
    protected $primaryKey = 'cafe_id';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'uuid',
        'user_id',
        'cafe_name',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($cafe) => $cafe->uuid = (string) Str::uuid());
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
}