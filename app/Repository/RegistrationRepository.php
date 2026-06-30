<?php

namespace App\Repository;

use App\Models\ApprovalList;
use App\Models\Cafe;
use App\Models\CafeBranch;
use App\Models\CafeDocument;
use App\Models\BranchDocument;
use App\Models\User;
use App\Models\UserDocument;

class RegistrationRepository
{
    public function updateUserProfile(User $user, array $payload): User
    {
        $user->update([
            'firstname'    => $payload['firstname'],
            'middlename'   => $payload['middlename'] ?? null,
            'lastname'     => $payload['lastname'],
            'username'     => $payload['username'],
            'phone_number' => $payload['phone_number'],
            'status'       => 'pending_approval',
        ]);

        return $user->fresh();
    }

    public function createUserDocument(int $userId, string $filePath, string $idType): UserDocument
    {
        return UserDocument::create([
            'user_id' => $userId,
            'file'    => $filePath,
            'id_type' => $idType,
        ]);
    }

    public function createCafe(int $userId, string $cafeName): Cafe
    {
        return Cafe::create([
            'user_id'   => $userId,
            'cafe_name' => $cafeName,
        ]);
    }

    public function createCafeDocument(int $cafeId, string $docType, string $filePath): CafeDocument
    {
        return CafeDocument::create([
            'cafe_id'  => $cafeId,
            'doc_type' => $docType,
            'file'     => $filePath,
        ]);
    }

    public function createBranch(int $cafeId, array $payload): CafeBranch
    {
        return CafeBranch::create([
            'cafe_id'          => $cafeId,
            'branch_name'      => $payload['branch_name'],
            'cafe_email'       => $payload['cafe_email'],
            'cafe_phonenumber' => $payload['cafe_phonenumber'],
            'address'          => $payload['address'],
            'branch_type'      => 'main',
            'status'           => 'pending_approval',
        ]);
    }

    public function createBranchDocument(int $branchId, string $docType, string $filePath): BranchDocument
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