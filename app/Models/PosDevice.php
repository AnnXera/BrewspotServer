<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * A browser/tablet registered as a register for one branch.
 *
 * The device owns its own Sanctum token (not the manager's), so the branch
 * is read from the token on every POS request and can't be spoofed by the
 * client. Revoking the device deletes that token.
 *
 * Implements Authenticatable only so framework code that expects an
 * authenticated user (e.g. rate limiting) works; it has no password.
 */
class PosDevice extends Model implements AuthenticatableContract
{
    use Authenticatable, HasApiTokens;

    protected $primaryKey = 'device_id';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'uuid',
        'branch_id',
        'name',
        'registered_by',
        'active_staff_id',
        'active_since',
        'last_used_at',
        'revoked_at',
    ];

    protected $casts = [
        'active_since' => 'datetime',
        'last_used_at' => 'datetime',
        'revoked_at'   => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($device) => $device->uuid = (string) Str::uuid());
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(CafeBranch::class, 'branch_id', 'branch_id');
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by', 'user_id');
    }

    public function activeStaff(): BelongsTo
    {
        return $this->belongsTo(CafeStaff::class, 'active_staff_id', 'staff_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
