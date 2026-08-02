<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CategoryBranch extends Model
{
    protected $primaryKey = 'cat_branch_id';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'uuid',
        'branch_id',
        'men_category_id',
        'is_available',
    ];

    protected $casts = [
        'is_available' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($override) => $override->uuid = (string) Str::uuid());
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(CafeBranch::class, 'branch_id', 'branch_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class, 'men_category_id', 'men_category_id');
    }
}