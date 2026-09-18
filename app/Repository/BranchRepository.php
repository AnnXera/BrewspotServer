<?php

namespace App\Repository;

use App\Models\ApprovalList;
use App\Models\BranchDocument;
use App\Models\CafeBranch;

class BranchRepository
{
    /**
     * Count active/pending branches for a given cafe.
     * Used for informational logging only — branch access is gated by the multi_branch feature flag.
     */
    public function countExistingBranches(int $cafeId): int
    {
        return CafeBranch::where('cafe_id', $cafeId)
            ->whereIn('status', ['pending_approval', 'active'])
            ->count();
    }

    public function create(int $cafeId, array $payload): CafeBranch
    {
        return CafeBranch::create([
            'cafe_id'          => $cafeId,
            'branch_name'      => $payload['branch_name'],
            'cafe_email'       => $payload['cafe_email'],
            'cafe_phonenumber' => $payload['cafe_phonenumber'],
            'address'          => $payload['address'],
            'branch_type'      => 'side',
            'status'           => 'pending_approval',
        ]);
    }

    public function createDocument(int $branchId, string $docType, string $filePath): BranchDocument
    {
        return BranchDocument::create([
            'branch_id' => $branchId,
            'doc_type'  => $docType,
            'file'      => $filePath,
        ]);
    }

    public function createApprovalEntry(int $userId, int $cafeId, int $branchId): ApprovalList
    {
        return ApprovalList::create([
            'user_id'   => $userId,
            'cafe_id'   => $cafeId,
            'branch_id' => $branchId,
            'status'    => 'pending_approval',
        ]);
    }

    /**
     * Find a branch by UUID scoped to the owner's cafes.
     * Returns null when the branch doesn't exist or doesn't belong to this owner.
     */
    public function findByUuidForOwner(int $userId, string $branchUuid): ?CafeBranch
    {
        return CafeBranch::where('uuid', $branchUuid)
            ->whereHas('cafe', fn ($q) => $q->where('user_id', $userId))
            ->with(['cafe', 'documents'])
            ->first();
    }

    /**
     * Update branch fields. Only non-null values in $data are applied.
     */
    public function update(CafeBranch $branch, array $data): CafeBranch
    {
        $branch->update($data);

        return $branch->fresh(['documents']);
    }

    /**
     * Replace the file path on an existing document row for a given type.
     * If no document of that type exists yet, create one.
     */
    public function updateDocument(int $branchId, string $docType, string $newPath): BranchDocument
    {
        return BranchDocument::updateOrCreate(
            ['branch_id' => $branchId, 'doc_type' => $docType],
            ['file' => $newPath]
        );
    }
}