<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * An owner's request to change registered cafe details, reviewed by an admin
 * on the Approval Status page (Cafe Updates tab). See the migration for types.
 */
class CafeUpdateRequest extends Model
{
    public const TYPE_NAME_CHANGE = 'name_change';

    protected $primaryKey = 'request_id';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'uuid',
        'cafe_id',
        'user_id',
        'type',
        'old_name',
        'new_name',
        'status',
        'reason',
        'can_resubmit',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at'  => 'datetime',
        'can_resubmit' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($request) => $request->uuid = (string) Str::uuid());
    }

    /** A pending request sent after an earlier request for the same cafe was rejected. */
    public function isResubmission(): bool
    {
        return $this->status === 'pending_approval'
            && static::where('cafe_id', $this->cafe_id)
                ->where('request_id', '<', $this->request_id)
                ->where('status', 'rejected')
                ->exists();
    }

    /** Query form of isResubmission(), for the Resubmitted tab and its count. */
    public function scopeResubmission($query)
    {
        return $query->where('cafe_update_requests.status', 'pending_approval')
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('cafe_update_requests as earlier')
                ->whereColumn('earlier.cafe_id', 'cafe_update_requests.cafe_id')
                ->whereColumn('earlier.request_id', '<', 'cafe_update_requests.request_id')
                ->where('earlier.status', 'rejected'));
    }

    /** The status to show: 'resubmitted' for a resubmission under review, else the stored status. */
    public function displayStatus(): string
    {
        return $this->isResubmission() ? 'resubmitted' : $this->status;
    }

    public function cafe(): BelongsTo
    {
        return $this->belongsTo(Cafe::class, 'cafe_id', 'cafe_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by', 'user_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CafeUpdateRequestDocument::class, 'request_id', 'request_id');
    }
}
