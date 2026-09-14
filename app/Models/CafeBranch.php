<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\SoftDeletes;

class CafeBranch extends Model
{
    use SoftDeletes;

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

    public function staff(): HasMany
    {
        return $this->hasMany(CafeStaff::class, 'branch_id', 'branch_id');
    }

    public function floorPlans(): HasMany
    {
        return $this->hasMany(FloorPlan::class, 'branch_id', 'branch_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'branch_id', 'branch_id');
    }

    public function inventoryServings(): HasMany
    {
        return $this->hasMany(InventoryServing::class, 'branch_id', 'branch_id');
    }

    public function menuBranches(): HasMany
    {
        return $this->hasMany(MenuBranch::class, 'branch_id', 'branch_id');
    }

    public function categoryBranches(): HasMany
    {
        return $this->hasMany(CategoryBranch::class, 'branch_id', 'branch_id');
    }
}