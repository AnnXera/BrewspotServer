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
     * An entry still waiting for review that corrects an earlier rejected one:
     * an owner application (main branch) after a rejected application by the same
     * owner, or a side branch sent back after that same branch was rejected.
     * It stays 'pending_approval' underneath, so reviews and filters work
     * unchanged; this only changes how it is labelled.
     */
    public function isResubmission(): bool
    {
        if ($this->status !== 'pending_approval') {
            return false;
        }

        $branch = $this->relationLoaded('branch')
            ? $this->branch
            : $this->branch()->withTrashed()->first();

        $earlier = static::where('approval_id', '<', $this->approval_id)->where('status', 'rejected');

        return match ($branch?->branch_type) {
            'main'  => $earlier->where('user_id', $this->user_id)->exists(),
            'side'  => $earlier->where('branch_id', $this->branch_id)->exists(),
            default => false,
        };
    }

    /**
     * Query form of isResubmission(). Used for the Resubmitted tab and its count.
     */
    public function scopeResubmission($query)
    {
        $rejectedBefore = fn (string $sameColumn) => fn ($q) => $q->selectRaw('1')
            ->from('approval_lists as earlier')
            ->whereColumn("earlier.{$sameColumn}", "approval_lists.{$sameColumn}")
            ->whereColumn('earlier.approval_id', '<', 'approval_lists.approval_id')
            ->where('earlier.status', 'rejected');

        return $query->where('approval_lists.status', 'pending_approval')
            ->where(fn ($q) => $q
                ->where(fn ($main) => $main
                    ->whereHas('branch', fn ($b) => $b->withTrashed()->where('branch_type', 'main'))
                    ->whereExists($rejectedBefore('user_id')))
                ->orWhere(fn ($side) => $side
                    ->whereHas('branch', fn ($b) => $b->withTrashed()->where('branch_type', 'side'))
                    ->whereExists($rejectedBefore('branch_id'))));
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