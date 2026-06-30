<?php

namespace App\Repository;

use App\Models\Cafe;
use App\Models\CafeBranch;
use App\Models\User;

class PasswordSetupRepository
{
    public function findApprovedOwnerByUuid(string $uuid): ?User
    {
        return User::where('uuid', $uuid)
            ->where('role_id', 2) // Cafe Owner
            ->where('status', 'approved')
            ->first();
    }

    public function activateOwner(User $owner, string $hashedPassword): User
    {
        $owner->update([
            'password_hash' => $hashedPassword,
            'status'        => 'active',
        ]);

        return $owner->fresh();
    }

    public function activateBranches(int $userId): void
    {
        $cafeIds = Cafe::where('user_id', $userId)->pluck('cafe_id');

        CafeBranch::whereIn('cafe_id', $cafeIds)
            ->where('status', 'pending_approval')
            ->update(['status' => 'active']);
    }
}