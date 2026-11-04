<?php

namespace App\Repository;

use App\Models\Cafe;
use App\Models\CafeStaff;
use App\Models\User;

class UserProfileRepository
{
    public function findByUuidOrFail(string $uuid): User
    {
        return User::where('uuid', $uuid)->firstOrFail();
    }

    public function findActiveAssignmentWithBranch(User $user): ?CafeStaff
    {
        return $user->activeStaffAssignments()->with('branch.cafe')->first();
    }

    public function findOwnerCafeWithBranches(User $user): ?Cafe
    {
        return $user->cafes()->with('branches')->first();
    }

    public function updateDetails(User $user, array $data): User
    {
        $user->update($data);

        return $user->fresh(['role']);
    }

    public function updatePasswordHash(User $user, string $hash): void
    {
        $user->password_hash = $hash;
        $user->save();
    }
}
