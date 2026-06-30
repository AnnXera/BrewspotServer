<?php

namespace App\Repository;

use App\Models\ApprovalList;
use App\Models\User;

class OwnerManagementRepository
{
    /**
     * Get all cafe owners with minimal fields for listing.
     */
    public function listOwners(int $perPage = 15)
    {
        return User::where('role_id', 2)
            ->with(['subscriptions' => fn ($q) => $q->latest('created_at')->limit(1)->with('plan')])
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Get one owner with full profile, cafe, and branches.
     */
    public function findOwnerByUuid(string $uuid): User
    {
        return User::where('uuid', $uuid)
            ->where('role_id', 2)
            ->with([
                'role',
                'cafes.documents',
                'cafes.branches.documents',
            ])
            ->firstOrFail();
    }

    /**
     * Update the owner's status.
     */
    public function updateStatus(User $owner, string $status): User
    {
        $owner->update(['status' => $status]);

        return $owner->fresh(['cafes.branches']);
    }

    /**
     * Find the latest approval entry for this owner.
     */
    public function findLatestApproval(int $userId): ?ApprovalList
    {
        return ApprovalList::where('user_id', $userId)
            ->latest('created_at')
            ->first();
    }

    /**
     * Update the approval entry to reflect the admin's decision.
     */
    public function updateApproval(ApprovalList $approval, string $status, int $reviewerId): ApprovalList
    {
        $approval->update([
            'status'      => $status,
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
        ]);

        return $approval->fresh();
    }

    /**
     * List all approval entries (pending, approved, rejected) for admin overview.
     */
    public function listApprovals(int $perPage = 15, ?string $status = null)
    {
        $query = ApprovalList::with(['user', 'cafe', 'branch', 'reviewer'])
            ->latest('created_at');

        if ($status) {
            $query->where('status', $status);
        }

        return $query->paginate($perPage);
    }
}