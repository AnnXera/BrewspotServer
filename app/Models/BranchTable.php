<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class BranchTable extends Model
{
    protected $primaryKey = 'table_id';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'uuid',
        'floorplan_id',
        'table_name',
        'capacity',
        'asset_key',
        'x_location',
        'y_location',
        'rotation',
        'status',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'x_location' => 'decimal:2',
        'y_location' => 'decimal:2',
        'rotation' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($table) => $table->uuid = (string) Str::uuid());
    }

    public function floorPlan(): BelongsTo
    {
        return $this->belongsTo(FloorPlan::class, 'floorplan_id', 'floor_plan_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'table_id', 'table_id');
    }
}
