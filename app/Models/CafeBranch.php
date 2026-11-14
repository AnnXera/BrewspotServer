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

    /**
     * Branches an admin has approved. Only these can be used: staff, POS, menu,
     * servings, reservations, floor plans. Pending and rejected branches can be
     * viewed but not operated. Inactive is an owner pause and stays usable.
     */
    public const OPERATIONAL_STATUSES = ['active', 'inactive'];

    protected static function booted(): void
    {
        static::creating(fn ($branch) => $branch->uuid = (string) Str::uuid());
    }

    public function isOperational(): bool
    {
        return in_array($this->status, self::OPERATIONAL_STATUSES, true);
    }

    public function scopeOperational($query)
    {
        return $query->whereIn('status', self::OPERATIONAL_STATUSES);
    }

    /** Why a non-operational branch can't be used, for API responses. */
    public function notOperationalMessage(): string
    {
        return match ($this->status) {
            'rejected'         => 'This branch was rejected by an administrator, so its features are unavailable.',
            'pending_approval' => 'This branch is still waiting for admin approval, so its features are unavailable.',
            default            => 'This branch is not available.',
        };
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

    public function posDevices(): HasMany
    {
        return $this->hasMany(PosDevice::class, 'branch_id', 'branch_id');
    }
}