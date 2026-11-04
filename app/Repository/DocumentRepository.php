<?php

namespace App\Repository;

use App\Models\BranchDocument;
use App\Models\CafeBranch;
use App\Models\CafeDocument;
use App\Models\UserDocument;

/**
 * Document lookups for the file endpoints. Trashed rows are included so admins
 * can still review the papers of archived applications.
 */
class DocumentRepository
{
    public function findUserDocument(int $id): ?UserDocument
    {
        return UserDocument::withTrashed()->find($id);
    }

    public function findCafeDocument(int $id): ?CafeDocument
    {
        return CafeDocument::withTrashed()
            ->with(['cafe' => fn ($q) => $q->withTrashed()])
            ->find($id);
    }

    public function findBranchDocument(int $id): ?BranchDocument
    {
        return BranchDocument::withTrashed()
            ->with(['branch' => fn ($q) => $q->withTrashed()->with(['cafe' => fn ($q2) => $q2->withTrashed()])])
            ->find($id);
    }

    public function findBranchByUuid(string $uuid): ?CafeBranch
    {
        return CafeBranch::withTrashed()->where('uuid', $uuid)->first();
    }
}
