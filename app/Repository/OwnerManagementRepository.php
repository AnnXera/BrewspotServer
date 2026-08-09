<?php

namespace App\Repository;

use App\Models\ApprovalList;
use App\Models\CafeBranch;
use App\Models\User;

class OwnerManagementRepository
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptionRepo
    ) {}
    /**
     * List owners with optional search / status / date filters.
     */
    public function listOwners(int $perPage = 15, ?string $search = null, ?string $status = null, ?string $date = null)
    {
        $query = User::where('role_id', 2)
            ->with([
                'cafes' => fn ($q) => $q->select('cafe_id', 'user_id', 'cafe_name'),
                'subscriptions' => fn ($q) => $q->latest('created_at')->limit(1)->with('plan'),
            ]);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('firstname', 'like', "%{$search}%")
                  ->orWhere('lastname', 'like', "%{$search}%")
                  ->orWhereHas('cafes', fn ($cq) => $cq->where('cafe_name', 'like', "%{$search}%"));
            });
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($date) {
            $query->whereDate('created_at', $date);
        }

        return $query->latest()->paginate($perPage);
    }

    /**
     * Stats for the top cards. 'inactive_or_suspended' is the combined
     * figure the UI card shows; the individual counts are included too
     * in case the frontend wants to split them later.
     */
    public function getStats(): array
    {
        $base = User::where('role_id', 2);

        return [
            'total_owners'          => (clone $base)->count(),
            'active'                => (clone $base)->where('status', 'active')->count(),
            'suspended'             => (clone $base)->where('status', 'suspended')->count(),
            'inactive'              => (clone $base)->where('status', 'inactive')->count(),
            'inactive_or_suspended' => (clone $base)->whereIn('status', ['inactive', 'suspended'])->count(),
        ];
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
                'documents',
                'cafes.documents',
                'cafes.branches.documents',
                'subscriptions' => fn ($q) => $q->latest('created_at')->with(['plan', 'latestPayment']),
            ])
            ->firstOrFail();
    }

    /**
     * Update the owner's status, cascading to their branches:
     * - suspend   → any currently 'active' branch becomes 'suspended'
     * - reinstate → any currently 'suspended' branch becomes 'active'
     *
     * Branches that are 'pending_approval' or 'rejected' are left alone —
     * they were never live, so suspension/reinstatement doesn't apply to them.
     */
    public function updateStatus(User $owner, string $status): User
    {
        $owner->update(['status' => $status]);

        $cafeIds = $owner->cafes()->pluck('cafe_id');

        if ($status === 'suspended') {
            CafeBranch::whereIn('cafe_id', $cafeIds)
                ->where('status', 'active')
                ->update(['status' => 'suspended']);

            $this->subscriptionRepo->cancelForSuspension($owner->user_id);
        } elseif ($status === 'active') {
            CafeBranch::whereIn('cafe_id', $cafeIds)
                ->where('status', 'suspended')
                ->update(['status' => 'active']);
        }

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
     * Only ever called for approved/rejected — see updateStatus() scoping rule.
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
     * $type: 'owner' = main branch (initial application), 'branch' = side branch submissions
     */
    public function listApprovals(int $perPage = 15, ?string $status = null, ?string $type = null)
    {
        $query = ApprovalList::with(['user', 'cafe', 'branch', 'reviewer'])
            ->latest('created_at');

        if ($status) {
            $query->where('status', $status);
        }

        if ($type === 'owner') {
            $query->whereHas('branch', fn ($q) => $q->where('branch_type', 'main'));
        } elseif ($type === 'branch') {
            $query->whereHas('branch', fn ($q) => $q->where('branch_type', 'side'));
        }

        return $query->paginate($perPage);
    }

    /**
     * Counts for the tab badges (General/Pending/Approved/Rejected), scoped
     * to the same 'owner' vs 'branch' distinction as listApprovals().
     */
    public function getApprovalStats(?string $type = null): array
    {
        $base = ApprovalList::query();

        if ($type === 'owner') {
            $base->whereHas('branch', fn ($q) => $q->where('branch_type', 'main'));
        } elseif ($type === 'branch') {
            $base->whereHas('branch', fn ($q) => $q->where('branch_type', 'side'));
        }

        return [
            'pending_approval' => (clone $base)->where('status', 'pending_approval')->count(),
            'approved'          => (clone $base)->where('status', 'approved')->count(),
            'rejected'          => (clone $base)->where('status', 'rejected')->count(),
        ];
    }

    public function findBranchByUuid(string $uuid): ?CafeBranch
    {
        return CafeBranch::where('uuid', $uuid)
            ->with('cafe.owner')
            ->first();
    }

    public function updateBranchStatus(CafeBranch $branch, string $branchStatus): CafeBranch
    {
        $branch->update(['status' => $branchStatus]);

        return $branch->fresh();
    }

    public function findLatestApprovalForBranch(int $branchId): ?ApprovalList
    {
        return ApprovalList::where('branch_id', $branchId)
            ->latest('created_at')
            ->first();
    }
}