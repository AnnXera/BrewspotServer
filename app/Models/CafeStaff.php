<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CafeStaff extends Model
{
    public const STATUS_ACTIVE     = 'active';
    public const STATUS_TERMINATED = 'terminated';

    // Statuses an owner/manager can set through an edit. Termination has its
    // own endpoint because it also revokes access.
    public const EDITABLE_STATUSES = ['active', 'inactive', 'suspended'];

    protected $table = 'cafe_staff';

    protected $primaryKey = 'staff_id';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'uuid',
        'user_id',
        'branch_id',
        'position',
        'employment_status',
        'hired_at',
        'terminated_at',
    ];

    protected $casts = [
        'hired_at'      => 'date',
        'terminated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($staff) => $staff->uuid = (string) Str::uuid());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(CafeBranch::class, 'branch_id', 'branch_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'staff_id', 'staff_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(StaffSchedule::class, 'staff_id', 'staff_id')->orderBy('day_of_week');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('employment_status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->employment_status === self::STATUS_ACTIVE;
    }
}