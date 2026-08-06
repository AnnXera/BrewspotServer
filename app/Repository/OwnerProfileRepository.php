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

    /**
     * Paginated for the branch card grid — defaults to 6 per page.
     */
    public function findBranchesByOwner(int $userId, int $perPage = 6)
    {
        return CafeBranch::whereHas('cafe', fn ($q) => $q->where('user_id', $userId))
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