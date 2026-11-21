<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApprovalList extends Model
{
    protected $primaryKey = 'approval_id';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'uuid',
        'user_id',
        'cafe_id',
        'branch_id',
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
        static::creating(fn ($approval) => $approval->uuid = (string) Str::uuid());
    }

    /**
     * An owner application (main branch) still waiting for review that corrects
     * an earlier rejected one. It stays 'pending_approval' underneath, so reviews
     * and filters work unchanged; this only changes how it is labelled.
     */
    public function isResubmission(): bool
    {
        if ($this->status !== 'pending_approval') {
            return false;
        }

        $branch = $this->relationLoaded('branch')
            ? $this->branch
            : $this->branch()->withTrashed()->first();

        if (($branch?->branch_type ?? null) !== 'main') {
            return false; // side-branch submissions are not resubmissions
        }

        return static::where('user_id', $this->user_id)
            ->where('approval_id', '<', $this->approval_id)
            ->where('status', 'rejected')
            ->exists();
    }

    /**
     * Query form of isResubmission(): pending owner applications (main branch)
     * that follow an earlier rejection by the same owner. Used for the
     * Resubmitted tab and its count.
     */
    public function scopeResubmission($query)
    {
        return $query->where('approval_lists.status', 'pending_approval')
            ->whereHas('branch', fn ($q) => $q->withTrashed()->where('branch_type', 'main'))
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('approval_lists as earlier')
                ->whereColumn('earlier.user_id', 'approval_lists.user_id')
                ->whereColumn('earlier.approval_id', '<', 'approval_lists.approval_id')
                ->where('earlier.status', 'rejected'));
    }

    /** Pending entries that are not resubmissions: first-time applications and branch submissions. */
    public function scopeFirstSubmission($query)
    {
        return $query->where('approval_lists.status', 'pending_approval')
            ->whereNotIn('approval_lists.approval_id', static::query()->resubmission()->select('approval_lists.approval_id'));
    }

    /** The status to show: 'resubmitted' for a resubmission under review, else the stored status. */
    public function displayStatus(): string
    {
        return $this->isResubmission() ? 'resubmitted' : $this->status;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function cafe(): BelongsTo
    {
        return $this->belongsTo(Cafe::class, 'cafe_id', 'cafe_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(CafeBranch::class, 'branch_id', 'branch_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by', 'user_id');
    }
}