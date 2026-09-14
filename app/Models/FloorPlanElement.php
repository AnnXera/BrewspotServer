<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class FloorPlanElement extends Model
{
    protected $primaryKey = 'element_id';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'uuid',
        'floorplan_id',
        'category',
        'asset_key',
        'label',
        'x_location',
        'y_location',
        'rotation',
        'z_index',
    ];

    protected $casts = [
        'x_location' => 'decimal:2',
        'y_location' => 'decimal:2',
        'rotation' => 'decimal:2',
        'z_index' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($element) => $element->uuid = (string) Str::uuid());
    }

    public function floorPlan(): BelongsTo
    {
        return $this->belongsTo(FloorPlan::class, 'floorplan_id', 'floor_plan_id');
    }
}
