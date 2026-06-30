<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CafeBranch extends Model
{
    protected $primaryKey = 'branch_id';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'uuid',
        'cafe_id',
        'branch_name',
        'cafe_picture',
        'cafe_email',
        'cafe_phonenumber',
        'address',
        'branch_type',
        'status',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($branch) => $branch->uuid = (string) Str::uuid());
    }

    public function cafe(): BelongsTo
    {
        return $this->belongsTo(Cafe::class, 'cafe_id', 'cafe_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(BranchDocument::class, 'branch_id', 'branch_id');
    }
}