<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CafeStaff extends Model
{
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
    ];

    protected $casts = [
        'hired_at' => 'date',
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
}