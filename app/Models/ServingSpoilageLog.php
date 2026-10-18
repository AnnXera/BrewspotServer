<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One spoilage entry on a day's serving. The serving's spoilage_qty is the sum
 * of its entries that are not voided. Size name and unit_value are copied when
 * logged, like a sold line's, so later menu edits don't change past costs.
 */
class ServingSpoilageLog extends Model
{
    public const REASONS = ['expired', 'dropped', 'wrong_order', 'quality', 'end_of_day', 'other'];

    protected $primaryKey = 'spoilage_log_id';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'uuid',
        'servings_id',
        'men_size_id',
        'size_name',
        'staff_id',
        'device_id',
        'quantity',
        'unit_value',
        'reason',
        'voided_at',
        'voided_by',
    ];

    protected $casts = [
        'quantity'   => 'integer',
        'unit_value' => 'decimal:2',
        'voided_at'  => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($log) => $log->uuid = (string) Str::uuid());
    }

    public function serving(): BelongsTo
    {
        return $this->belongsTo(InventoryServing::class, 'servings_id', 'servings_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(CafeStaff::class, 'staff_id', 'staff_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(PosDevice::class, 'device_id', 'device_id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by', 'user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }
}
