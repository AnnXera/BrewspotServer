<?php

namespace App\Repository;

use App\Models\Cafe;
use App\Models\CafeBranch;
use App\Models\User;

class OwnerProfileRepository
{
    public function findOwnerWithRole(int $userId): User
    {
        return User::with('role')->findOrFail($userId);
    }

    public function findCafesByOwner(int $userId)
    {
        return Cafe::where('user_id', $userId)
            ->with('documents')
            ->get();
    }

    public function findCafeNameByOwner(int $userId): ?string
    {
        return Cafe::where('user_id', $userId)
            ->oldest('created_at')
            ->value('cafe_name');
    }

    /**
     * Paginated for the branch card grid — defaults to 6 per page.
     */
    public function findBranchesByOwner(int $userId, int $perPage = 6, ?string $search = null, ?string $status = null)
    {
        return CafeBranch::whereHas('cafe', fn ($q) => $q->where('user_id', $userId))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('branch_name', 'like', "%{$search}%")
                        ->orWhereHas('cafe', fn ($cq) => $cq->where('cafe_name', 'like', "%{$search}%"));
                });
            })
            ->latest('created_at')
            ->paginate($perPage);
    }

    public function findBranchByUuid(int $userId, string $branchUuid): ?CafeBranch
    {
        return CafeBranch::where('uuid', $branchUuid)
            ->whereHas('cafe', fn ($q) => $q->where('user_id', $userId))
            ->with(['cafe', 'documents'])
            ->first();
    }
}