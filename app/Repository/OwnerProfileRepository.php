<?php

namespace App\Repository;

use App\Models\Cafe;
use App\Models\CafeBranch;
use App\Models\CafeStaff;
use App\Models\User;

class OwnerProfileRepository
{
    public function findOwnerWithRole(int $userId): User
    {
        return User::with('role')->findOrFail($userId);
    }

    public function markWelcomed(User $owner): void
    {
        $owner->forceFill(['welcomed_at' => now()])->save();
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

    public function findPrimaryCafeByOwner(int $userId): ?Cafe
    {
        return Cafe::where('user_id', $userId)
            ->oldest('created_at')
            ->first();
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
                        ->orWhereHas('cafe', fn ($cq) => $cq->where('cafe_name', 'like', "%{$search}%"))
                        // The search box says "branch or manager": match the same active managers
                        // the list's Manager column shows. Every word has to match their first or
                        // last name, so "Maria", "Santos" and "Maria Santos" all find Maria Santos.
                        ->orWhereHas('staff', fn ($staff) => $staff
                            ->where('employment_status', CafeStaff::STATUS_ACTIVE)
                            ->whereHas('user.role', fn ($r) => $r->where('role_name', 'Manager'))
                            ->whereHas('user', function ($u) use ($search) {
                                foreach (preg_split('/\s+/', trim($search)) as $word) {
                                    $u->where(fn ($w) => $w
                                        ->where('firstname', 'like', "%{$word}%")
                                        ->orWhere('lastname', 'like', "%{$word}%"));
                                }
                            }));
                });
            })
            // Active managers only, for the "Manager" column on the branch list.
            ->with(['staff' => fn ($q) => $q
                ->where('employment_status', CafeStaff::STATUS_ACTIVE)
                ->whereHas('user.role', fn ($r) => $r->where('role_name', 'Manager'))
                ->with('user:user_id,firstname,lastname')])
            ->latest('created_at')
            ->paginate($perPage);
    }

    /**
     * How many of the owner's branches are in each status, across all of them — not just the
     * page or filter the list is showing — for the summary cards above the branch list.
     *
     * @return array<string, int> status => count
     */
    public function countBranchesByStatus(int $userId): array
    {
        return CafeBranch::whereHas('cafe', fn ($q) => $q->where('user_id', $userId))
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    public function findBranchByUuid(int $userId, string $branchUuid): ?CafeBranch
    {
        return CafeBranch::where('uuid', $branchUuid)
            ->whereHas('cafe', fn ($q) => $q->where('user_id', $userId))
            ->with(['cafe', 'documents'])
            ->first();
    }
}