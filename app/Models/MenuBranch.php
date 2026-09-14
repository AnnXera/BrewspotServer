<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MenuBranch extends Model
{
    protected $primaryKey = 'men_branch_id';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'uuid',
        'branch_id',
        'men_item_id',
        'is_available',
        'branch_price',
    ];

    protected $casts = [
        'is_available' => 'boolean',
        'branch_price' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($override) => $override->uuid = (string) Str::uuid());
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(CafeBranch::class, 'branch_id', 'branch_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'men_item_id', 'men_item_id');
    }
}
