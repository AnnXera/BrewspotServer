<?php

namespace App\Repository;

use App\Models\Cafe;
use App\Models\CafeBranch;
use App\Models\User;

class PasswordSetupRepository
{
    /**
     * Was: findApprovedOwnerByUuid() — restricted to role_id 2 + status 'approved'.
     * Renamed + broadened so the same /auth/setup-password/{uuid} endpoint
     * also serves staff accounts (role Manager/Cashier, status 'pending_setup'),
     * which the owner creates directly rather than via self-registration.
     */
    public function findAccountAwaitingSetupByUuid(string $uuid): ?User
    {
        return User::where('uuid', $uuid)
            ->with('role')
            ->where(function ($query) {
                $query->where(function ($owner) {
                    $owner->whereHas('role', fn ($r) => $r->where('role_name', 'Cafe Owner'))
                          ->where('status', 'approved');
                })->orWhere(function ($staff) {
                    $staff->whereHas('role', fn ($r) => $r->whereIn('role_name', ['Manager', 'Cashier']))
                          ->where('status', 'pending_setup');
                });
            })
            ->first();
    }

    /**
     * Finds the account by uuid regardless of status — used only to figure out
     * *why* setup isn't available (already active vs. genuinely invalid link),
     * so we can show a more specific message than findAccountAwaitingSetupByUuid().
     */
    public function findAccountByUuid(string $uuid): ?User
    {
        return User::where('uuid', $uuid)
            ->with('role')
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