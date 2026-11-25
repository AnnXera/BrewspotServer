<?php

namespace App\Repository;

use App\Models\BranchDocument;
use App\Models\Cafe;
use App\Models\CafeBranch;
use App\Models\CafeUpdateRequest;
use App\Models\CafeUpdateRequestDocument;
use Illuminate\Support\Collection;

class CafeUpdateRepository
{
    /**
     * Branches whose COR must be updated for a name change: every branch that is or
     * may become operational. Rejected branches are left out (a resubmission sends its
     * own COR, under whatever the cafe is called by then).
     */
    public const BRANCH_STATUSES_NEEDING_COR = ['active', 'inactive', 'pending_approval', 'suspended'];

    private const WITH = ['cafe', 'owner', 'reviewer', 'documents.branch'];

    public function branchesNeedingCor(int $cafeId): Collection
    {
        return CafeBranch::where('cafe_id', $cafeId)
            ->whereIn('status', self::BRANCH_STATUSES_NEEDING_COR)
            ->with('documents')
            ->orderByRaw("CASE WHEN branch_type = 'main' THEN 0 ELSE 1 END")
            ->orderBy('branch_id')
            ->get();
    }

    public function latestForCafe(int $cafeId): ?CafeUpdateRequest
    {
        return CafeUpdateRequest::where('cafe_id', $cafeId)
            ->with(self::WITH)
            ->latest('request_id')
            ->first();
    }

    public function pendingForCafe(int $cafeId): ?CafeUpdateRequest
    {
        return CafeUpdateRequest::where('cafe_id', $cafeId)
            ->where('status', 'pending_approval')
            ->first();
    }

    public function hasPendingForCafe(int $cafeId): bool
    {
        return CafeUpdateRequest::where('cafe_id', $cafeId)->where('status', 'pending_approval')->exists();
    }

    public function findForOwner(int $userId, string $uuid): ?CafeUpdateRequest
    {
        return CafeUpdateRequest::where('uuid', $uuid)
            ->whereHas('cafe', fn ($q) => $q->where('user_id', $userId))
            ->first();
    }

    public function findByUuid(string $uuid): ?CafeUpdateRequest
    {
        return CafeUpdateRequest::where('uuid', $uuid)->with(self::WITH)->first();
    }

    public function create(Cafe $cafe, int $userId, string $newName): CafeUpdateRequest
    {
        return CafeUpdateRequest::create([
            'cafe_id'  => $cafe->cafe_id,
            'user_id'  => $userId,
            'type'     => CafeUpdateRequest::TYPE_NAME_CHANGE,
            'old_name' => $cafe->cafe_name,
            'new_name' => $newName,
            'status'   => 'pending_approval',
        ]);
    }

    public function addDocument(CafeUpdateRequest $request, int $branchId, string $path, string $registeredAt): CafeUpdateRequestDocument
    {
        return $request->documents()->create([
            'branch_id'     => $branchId,
            'file'          => $path,
            'registered_at' => $registeredAt,
        ]);
    }

    /** Admin list. $status: null/'' (all), 'pending', 'resubmitted', or a stored status. */
    public function list(int $perPage, ?string $status, ?string $search)
    {
        $query = CafeUpdateRequest::with(self::WITH)->latest('request_id');

        match ($status) {
            null, ''      => null,
            'pending'     => $query->where('status', 'pending_approval')
                ->whereNotIn('request_id', CafeUpdateRequest::query()->resubmission()->select('cafe_update_requests.request_id')),
            'resubmitted' => $query->resubmission(),
            default       => $query->where('status', $status),
        };

        if ($search) {
            $query->where(fn ($q) => $q
                ->where('old_name', 'like', "%{$search}%")
                ->orWhere('new_name', 'like', "%{$search}%")
                ->orWhereHas('owner', fn ($o) => $o
                    ->where('firstname', 'like', "%{$search}%")
                    ->orWhere('lastname', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")));
        }

        return $query->paginate($perPage);
    }

    /** Same keys as the owner/branch approval stats, for the tab badges. */
    public function stats(): array
    {
        $resubmitted = CafeUpdateRequest::query()->resubmission()->count();
        $pendingAll  = CafeUpdateRequest::where('status', 'pending_approval')->count();

        return [
            'pending_approval' => $pendingAll,
            'pending'          => $pendingAll - $resubmitted,
            'resubmitted'      => $resubmitted,
            'approved'         => CafeUpdateRequest::where('status', 'approved')->count(),
            'rejected'         => CafeUpdateRequest::where('status', 'rejected')->count(),
        ];
    }

    /** Every request an owner sent, oldest first (for the admin's Application History). */
    public function historyForOwner(int $userId): Collection
    {
        return CafeUpdateRequest::where('user_id', $userId)->with('reviewer')->orderBy('request_id')->get();
    }

    public function currentBir(int $branchId): ?BranchDocument
    {
        return BranchDocument::where('branch_id', $branchId)->where('doc_type', 'BIR')->latest('branch_doc_id')->first();
    }
}
