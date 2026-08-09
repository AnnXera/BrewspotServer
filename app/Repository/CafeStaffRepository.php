<?php

namespace App\Repository;

use App\Models\Cafe;
use App\Models\CafeBranch;
use App\Models\CafeStaff;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;

class CafeStaffRepository
{
    public function findCafeByOwner(int $ownerUserId): ?Cafe
    {
        return Cafe::where('user_id', $ownerUserId)->first();
    }

    /**
     * Only branches that actually belong to this cafe are returned —
     * the service compares the count against the requested UUIDs to
     * catch anyone trying to assign a branch they don't own.
     */
    public function findOwnedBranches(int $cafeId, array $branchUuids)
    {
        return CafeBranch::where('cafe_id', $cafeId)
            ->whereIn('uuid', array_unique($branchUuids))
            ->get();
    }

    public function findRoleByName(string $roleName): ?Role
    {
        return Role::where('role_name', $roleName)->first();
    }

    public function createStaffUser(array $payload, int $roleId): User
    {
        return User::create([
            'firstname'         => $payload['firstname'],
            'middlename'        => $payload['middlename'] ?? null,
            'lastname'          => $payload['lastname'],
            'email'             => $payload['email'],
            'phone_number'      => $payload['phone_number'] ?? null,
            'role_id'           => $roleId,
            'status'            => 'pending_setup',
            // Owner-created accounts skip self-service email verification —
            // the owner is vouching for this address directly.
            'email_verified_at' => Carbon::now(),
        ]);
    }

    public function assignToBranch(int $userId, int $branchId, ?string $position): CafeStaff
    {
        return CafeStaff::create([
            'user_id'           => $userId,
            'branch_id'         => $branchId,
            'position'          => $position,
            'employment_status' => 'active',
        ]);
    }

    public function listByCafe(int $cafeId, int $perPage = 15)
    {
        return User::whereHas('staffAssignments.branch', fn ($q) => $q->where('cafe_id', $cafeId))
            ->whereHas('role', fn ($q) => $q->whereIn('role_name', ['Manager', 'Cashier']))
            ->with([
                'role',
                'staffAssignments' => fn ($q) => $q
                    ->whereHas('branch', fn ($bq) => $bq->where('cafe_id', $cafeId))
                    ->with('branch'),
            ])
            ->latest('created_at')
            ->paginate($perPage);
    }

    public function findStaffUserForCafe(string $uuid, int $cafeId): ?User
    {
        return User::where('uuid', $uuid)
            ->whereHas('staffAssignments.branch', fn ($q) => $q->where('cafe_id', $cafeId))
            ->with([
                'role',
                'staffAssignments' => fn ($q) => $q
                    ->whereHas('branch', fn ($bq) => $bq->where('cafe_id', $cafeId))
                    ->with('branch'),
            ])
            ->first();
    }

    public function deactivateAllAssignments(int $userId): void
    {
        CafeStaff::where('user_id', $userId)->update(['employment_status' => 'inactive']);
    }
}