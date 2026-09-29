<?php

namespace App\Repository;

use App\Models\CafeBranch;
use App\Models\CafeStaff;
use App\Models\PosDevice;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PosDeviceRepository
{
    /**
     * Active branches this owner/manager is allowed to set up a register for.
     */
    public function findRegisterableBranches(User $user): Collection
    {
        $query = CafeBranch::where('status', 'active')->orderBy('branch_name');

        if ($user->isOwner()) {
            return $query->whereHas('cafe', fn ($q) => $q->where('user_id', $user->user_id))->get();
        }

        return $query->whereHas('staff', fn ($q) => $q->where('user_id', $user->user_id)->active())->get();
    }

    public function create(int $branchId, string $name, int $registeredBy): PosDevice
    {
        return PosDevice::create([
            'branch_id'     => $branchId,
            'name'          => $name,
            'registered_by' => $registeredBy,
        ]);
    }

    public function listForBranch(int $branchId): Collection
    {
        return PosDevice::where('branch_id', $branchId)
            ->whereNull('revoked_at')
            ->with(['branch', 'registeredBy', 'activeStaff.user'])
            ->latest('created_at')
            ->get();
    }

    public function findForBranch(string $deviceUuid, int $branchId): ?PosDevice
    {
        return PosDevice::where('uuid', $deviceUuid)
            ->where('branch_id', $branchId)
            ->whereNull('revoked_at')
            ->first();
    }

    public function revoke(PosDevice $device): void
    {
        $device->tokens()->delete();

        $device->forceFill([
            'revoked_at'      => Carbon::now(),
            'active_staff_id' => null,
            'active_since'    => null,
        ])->save();
    }

    /**
     * Staff shown on the register's lock screen: everyone with an active
     * assignment at this branch whose account isn't deactivated.
     */
    public function findUnlockableStaff(int $branchId): Collection
    {
        return CafeStaff::where('branch_id', $branchId)
            ->active()
            ->whereHas('user', fn ($q) => $q
                ->where('status', '!=', 'inactive')
                ->whereHas('role', fn ($r) => $r->whereIn('role_name', ['Manager', 'Cashier'])))
            ->with('user.role')
            ->get()
            ->sortBy(fn (CafeStaff $a) => $a->user->firstname)
            ->values();
    }

    public function findUnlockableAssignment(string $userUuid, int $branchId): ?CafeStaff
    {
        return CafeStaff::where('branch_id', $branchId)
            ->active()
            ->whereHas('user', fn ($q) => $q
                ->where('uuid', $userUuid)
                ->where('status', '!=', 'inactive')
                ->whereHas('role', fn ($r) => $r->whereIn('role_name', ['Manager', 'Cashier'])))
            ->with('user.role')
            ->first();
    }

    public function setActiveStaff(PosDevice $device, ?CafeStaff $assignment): void
    {
        $device->forceFill([
            'active_staff_id' => $assignment?->staff_id,
            'active_since'    => $assignment ? Carbon::now() : null,
        ])->save();
    }
}
