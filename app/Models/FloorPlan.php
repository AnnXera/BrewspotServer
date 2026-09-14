<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class FloorPlan extends Model
{
    use SoftDeletes;

    protected $primaryKey = 'floor_plan_id';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'uuid',
        'branch_id',
        'floorplan_name',
        'canvas_width',
        'canvas_height',
        'boundary_points',
        'is_active',
    ];

    protected $casts = [
        'canvas_width' => 'decimal:2',
        'canvas_height' => 'decimal:2',
        'boundary_points' => 'array',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($plan) => $plan->uuid = (string) Str::uuid());
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(CafeBranch::class, 'branch_id', 'branch_id');
    }

    public function tables(): HasMany
    {
        return $this->hasMany(BranchTable::class, 'floorplan_id', 'floor_plan_id');
    }

    public function elements(): HasMany
    {
        return $this->hasMany(FloorPlanElement::class, 'floorplan_id', 'floor_plan_id');
    }
}
