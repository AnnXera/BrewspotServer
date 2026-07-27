<?php

namespace App\Repository;

use App\Models\ApprovalList;
use App\Models\BranchDocument;
use App\Models\CafeBranch;

class BranchRepository
{
    /**
     * Branches that currently count against the plan's max_branches quota.
     * Rejected/inactive branches don't count — they freed up the slot.
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
}