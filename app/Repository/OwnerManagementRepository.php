<?php

namespace App\Repository;

use App\Models\ApprovalList;
use App\Models\CafeBranch;
use App\Models\User;

class OwnerManagementRepository
{
    /**
     * Owner Management only ever shows accounts that have actually onboarded
     * onto the platform — i.e. gone through approval and set up a password.
     * Anyone still mid-application (email_unverified, filling_application,
     * pending_approval, approved-but-not-yet-set-up) or rejected outright
     * belongs in the Approval Status screens, not here.
     */
    private const SAAS_STATUSES = ['active', 'inactive', 'suspended'];

    public function __construct(
        private readonly SubscriptionRepository $subscriptionRepo
    ) {}

    public function listOwners(int $perPage = 15, ?string $search = null, ?string $status = null, ?string $date = null)
    {
        $query = User::where('role_id', 2)
            ->whereIn('status', self::SAAS_STATUSES)
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

    public function getStats(): array
    {
        $base = User::where('role_id', 2)->whereIn('status', self::SAAS_STATUSES);

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
     * Scoped to SAAS_STATUSES so a direct-URL visit to /admin/owners/{uuid}
     * for a still-applying or rejected user 404s instead of leaking data
     * outside the intended Owner Management surface.
     *
     * NOTE: This is for the Owner Management list/detail pages ONLY.
     * Do not reuse this for updateStatus() — see findPendingApplicantByUuid()
     * and findSaasOwnerForStatusChange() below.
     */
    public function findOwnerByUuid(string $uuid): User
    {
        return User::where('uuid', $uuid)
            ->where('role_id', 2)
            ->whereIn('status', self::SAAS_STATUSES)
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
     * Used by updateStatus() for the approve/reject decision only.
     * A user is only ever actionable here while still pending_approval —
     * once already decided, re-hitting the same uuid should 404 rather
     * than silently re-applying a decision on an owner who has since
     * moved into a SAAS status.
     */
    public function findPendingApplicantByUuid(string $uuid): User
    {
        return User::where('uuid', $uuid)
            ->where('role_id', 2)
            ->where('status', 'pending_approval')
            ->firstOrFail();
    }

    /**
     * Used by updateStatus() for suspend/reactivate only.
     * Kept separate from findOwnerByUuid() (which eager-loads the full
     * profile graph for the detail page) since this call only needs the
     * bare User row before mutating its status.
     */
    public function findSaasOwnerForStatusChange(string $uuid): User
    {
        return User::where('uuid', $uuid)
            ->where('role_id', 2)
            ->whereIn('status', self::SAAS_STATUSES)
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
     * Find the approval row for the owner's CURRENT, active application cycle.
     * Filtering by status='pending_approval' guarantees we never accidentally
     * target a stale/already-decided row from a prior attempt. Ordering by
     * approval_id (not created_at) sidesteps second-precision timestamp ties
     * when testing rejections in quick succession.
     */
    public function findLatestApproval(int $userId): ?ApprovalList
    {
        return ApprovalList::where('user_id', $userId)
            ->where('status', 'pending_approval')
            ->latest('approval_id')
            ->first();
    }

    /**
     * Update the approval entry to reflect the admin's decision.
     * Only ever called for approved/rejected — see updateStatus() scoping rule.
     */
    public function updateApproval(ApprovalList $approval, string $status, int $reviewerId, ?string $reason = null): ApprovalList
    {
        $approval->update([
            'status'      => $status,
            'reason'      => $status === 'rejected' ? $reason : null,
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
        ]);

        return $approval->fresh();
    }

    /**
     * List all approval entries (pending, approved, rejected) for admin overview.
     * $type: 'owner' = main branch (initial application), 'branch' = side branch submissions
     * $search: matches against the applicant's name or cafe name (including
     * archived cafes from a rejected-and-reapplied attempt).
     *
     * withTrashed() inside whereHas() is required — CafeBranch/Cafe use
     * SoftDeletes, and once an owner is rejected + reapplies, their old
     * branch/cafe is archived. Without withTrashed(), the EXISTS subquery
     * excludes trashed rows and the whole approval row (or search match)
     * silently disappears, even though it was never deleted itself.
     */
    public function listApprovals(int $perPage = 15, ?string $status = null, ?string $type = null, ?string $search = null)
    {
        $query = ApprovalList::with(['user', 'cafe', 'branch', 'reviewer'])
            ->latest('created_at');

        if ($status) {
            $query->where('status', $status);
        }

        if ($type === 'owner') {
            $query->whereHas('branch', fn ($q) => $q->withTrashed()->where('branch_type', 'main'));
        } elseif ($type === 'branch') {
            $query->whereHas('branch', fn ($q) => $q->withTrashed()->where('branch_type', 'side'));
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', fn ($uq) => $uq
                        ->where('firstname', 'like', "%{$search}%")
                        ->orWhere('lastname', 'like', "%{$search}%")
                    )
                    ->orWhereHas('cafe', fn ($cq) => $cq
                        ->withTrashed()
                        ->where('cafe_name', 'like', "%{$search}%")
                    );
            });
        }

        return $query->paginate($perPage);
    }

    /**
     * Counts for the tab badges (General/Pending/Approved/Rejected), scoped
     * to the same 'owner' vs 'branch' distinction as listApprovals().
     * Intentionally NOT filtered by search — badge counts always reflect
     * the full set for that registration type, independent of the search box.
     */
    public function getApprovalStats(?string $type = null): array
    {
        $base = ApprovalList::query();

        if ($type === 'owner') {
            $base->whereHas('branch', fn ($q) => $q->withTrashed()->where('branch_type', 'main'));
        } elseif ($type === 'branch') {
            $base->whereHas('branch', fn ($q) => $q->withTrashed()->where('branch_type', 'side'));
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

    /**
     * Same status filter applied as findLatestApproval() — guarantees we
     * never target a stale/already-decided row from a prior branch attempt.
     */
    public function findLatestApprovalForBranch(int $branchId): ?ApprovalList
    {
        return ApprovalList::where('branch_id', $branchId)
            ->where('status', 'pending_approval')
            ->latest('approval_id')
            ->first();
    }

    public function findApplicationHistory(int $userId)
    {
        return ApprovalList::where('user_id', $userId)
            ->with([
                'cafe'             => fn ($q) => $q->withTrashed(),
                'cafe.documents'   => fn ($q) => $q->withTrashed(),
                'branch'           => fn ($q) => $q->withTrashed(),
                'branch.documents' => fn ($q) => $q->withTrashed(),
                'reviewer',
            ])
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Snapshot of the cafe/branch/documents exactly as they were for THIS
     * approval row — including trashed data from a since-superseded
     * (rejected + reapplied) application. Unlike findOwnerByUuid(), this
     * never falls through to the owner's current live cafe/branch, so an
     * admin reviewing an old rejected row always sees what was actually
     * submitted at that time, not whatever the owner has now.
     */
    public function findApprovalSnapshot(string $approvalUuid): ?ApprovalList
    {
        return ApprovalList::where('uuid', $approvalUuid)
            ->with([
                'user',
                'reviewer',
                'cafe'             => fn ($q) => $q->withTrashed(),
                'cafe.documents'   => fn ($q) => $q->withTrashed(),
                'branch'           => fn ($q) => $q->withTrashed(),
                'branch.documents' => fn ($q) => $q->withTrashed(),
            ])
            ->first();
    }
}