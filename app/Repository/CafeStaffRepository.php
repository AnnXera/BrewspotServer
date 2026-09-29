<?php

namespace App\Repository;

use App\Models\Cafe;
use App\Models\CafeBranch;
use App\Models\CafeOpeningHour;
use App\Models\CafeStaff;
use App\Models\PosDevice;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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

    /**
     * Managers get a dashboard login, so they start in 'pending_setup' and
     * receive a password setup email. Cashiers never get a password — they
     * sign in on a registered POS with their PIN — so they're 'active' at once.
     */
    public function createStaffUser(array $payload, Role $role): User
    {
        return User::create([
            'firstname'         => $payload['firstname'],
            'middlename'        => $payload['middlename'] ?? null,
            'lastname'          => $payload['lastname'],
            'email'             => $payload['email'],
            'phone_number'      => $payload['phone_number'] ?? null,
            'address'           => $payload['address'] ?? null,
            'role_id'           => $role->role_id,
            'status'            => $role->role_name === 'Manager' ? 'pending_setup' : 'active',
            // Owner-created accounts skip self-service email verification —
            // the owner is vouching for this address directly.
            'email_verified_at' => Carbon::now(),
        ]);
    }

    public function assignToBranch(int $userId, int $branchId, ?string $hiredAt = null): CafeStaff
    {
        return CafeStaff::create([
            'user_id'           => $userId,
            'branch_id'         => $branchId,
            'employment_status' => CafeStaff::STATUS_ACTIVE,
            'hired_at'          => $hiredAt,
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

    public function terminateAllAssignments(int $userId): void
    {
        CafeStaff::where('user_id', $userId)
            ->where('employment_status', '!=', CafeStaff::STATUS_TERMINATED)
            ->update([
                'employment_status' => CafeStaff::STATUS_TERMINATED,
                'terminated_at'     => Carbon::now(),
            ]);
    }

    // ── Branch-scoped (owner + manager) ──────────────────────────────────

    /**
     * Staff with an assignment at this branch, for the Employees tab.
     *
     * Filters: search (name / email / phone, every word must match),
     * status (employment status at this branch), role (Manager / Cashier).
     * Sorted active first, terminated last, then by name.
     *
     * @param  array{search?: ?string, status?: ?string, role?: ?string}  $filters
     */
    public function listByBranch(int $branchId, array $filters, int $perPage = 15)
    {
        $status = $filters['status'] ?? null;
        $role   = $filters['role'] ?? null;
        $words  = preg_split('/\s+/', trim($filters['search'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);

        $statusOrder = CafeStaff::selectRaw(
            "CASE employment_status WHEN 'active' THEN 0 WHEN 'terminated' THEN 2 ELSE 1 END"
        )
            ->whereColumn('cafe_staff.user_id', 'users.user_id')
            ->where('branch_id', $branchId)
            ->limit(1);

        return User::whereHas('staffAssignments', function ($q) use ($branchId, $status) {
            $q->where('branch_id', $branchId);

            if ($status) {
                $q->where('employment_status', $status);
            }
        })
            ->whereHas('role', fn ($q) => $q->whereIn('role_name', $role ? [$role] : ['Manager', 'Cashier']))
            ->when($words, function ($query) use ($words) {
                foreach ($words as $word) {
                    $like = '%' . addcslashes($word, '%_\\') . '%';

                    $query->where(fn ($q) => $q
                        ->where('firstname', 'like', $like)
                        ->orWhere('lastname', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('phone_number', 'like', $like));
                }
            })
            ->with($this->branchViewRelations($branchId))
            ->orderBy($statusOrder)
            ->orderBy('firstname')
            ->orderBy('lastname')
            ->paginate($perPage);
    }

    /**
     * Counts for the Employees tab cards. Not affected by the list filters.
     *
     * @return array{total: int, active: int, inactive: int, suspended: int, terminated: int}
     */
    public function countByBranch(int $branchId): array
    {
        $counts = CafeStaff::where('branch_id', $branchId)
            ->whereHas('user.role', fn ($q) => $q->whereIn('role_name', ['Manager', 'Cashier']))
            ->selectRaw('employment_status, COUNT(*) as aggregate')
            ->groupBy('employment_status')
            ->pluck('aggregate', 'employment_status');

        return [
            'total'      => (int) $counts->sum(),
            'active'     => (int) ($counts['active'] ?? 0),
            'inactive'   => (int) ($counts['inactive'] ?? 0),
            'suspended'  => (int) ($counts['suspended'] ?? 0),
            'terminated' => (int) ($counts['terminated'] ?? 0),
        ];
    }

    public function findStaffUserAtBranch(string $userUuid, int $branchId): ?User
    {
        return User::where('uuid', $userUuid)
            ->whereHas('staffAssignments', fn ($q) => $q->where('branch_id', $branchId))
            ->whereHas('role', fn ($q) => $q->whereIn('role_name', ['Manager', 'Cashier']))
            ->with($this->branchViewRelations($branchId))
            ->first();
    }

    /**
     * `staffAssignments` holds only this branch's row (what StaffMemberResource
     * shows); `activeStaffAssignments` feeds the "Also at: …" hint.
     */
    private function branchViewRelations(int $branchId): array
    {
        return [
            'role',
            'staffAssignments'              => fn ($q) => $q->where('branch_id', $branchId)->with(['branch', 'schedules']),
            'activeStaffAssignments.branch',
        ];
    }

    public function updateUser(User $user, array $attributes): User
    {
        $user->fill($attributes)->save();

        return $user;
    }

    public function updateAssignment(CafeStaff $assignment, array $attributes): CafeStaff
    {
        $assignment->fill($attributes)->save();

        return $assignment;
    }

    public function terminateAssignment(CafeStaff $assignment): void
    {
        $assignment->forceFill([
            'employment_status' => CafeStaff::STATUS_TERMINATED,
            'terminated_at'     => Carbon::now(),
        ])->save();
    }

    public function hasActiveAssignments(int $userId): bool
    {
        return CafeStaff::where('user_id', $userId)->active()->exists();
    }

    /**
     * Replaces the whole weekly schedule for one branch assignment.
     *
     * @param  array<int, array{day_of_week:int, is_day_off:bool, start_time:?string, end_time:?string}>  $days
     */
    public function replaceSchedule(CafeStaff $assignment, array $days): void
    {
        $assignment->schedules()->delete();

        foreach ($days as $day) {
            $assignment->schedules()->create([
                'day_of_week' => $day['day_of_week'],
                'is_day_off'  => $day['is_day_off'],
                'start_time'  => $day['is_day_off'] ? null : $day['start_time'],
                'end_time'    => $day['is_day_off'] ? null : $day['end_time'],
            ]);
        }
    }

    /**
     * @return Collection<string, CafeOpeningHour> keyed by day name ("Monday")
     */
    public function findOpeningHours(int $cafeId): Collection
    {
        return CafeOpeningHour::where('cafe_id', $cafeId)->get()->keyBy('day_of_week');
    }

    /**
     * Cuts off every way this user can act: dashboard tokens, and any POS
     * register they're currently unlocked on.
     */
    public function revokeAccess(User $user): void
    {
        $user->tokens()->delete();

        $this->endPosSessions(CafeStaff::where('user_id', $user->user_id)->pluck('staff_id')->all());
    }

    /**
     * Sends any register these assignments are unlocked on back to the lock screen.
     *
     * @param  array<int>  $staffIds
     */
    public function endPosSessions(array $staffIds): void
    {
        PosDevice::whereIn('active_staff_id', $staffIds)->update([
            'active_staff_id' => null,
            'active_since'    => null,
        ]);
    }

    /**
     * Branches a manager can switch between — only active assignments.
     */
    public function findManagedBranches(int $userId): Collection
    {
        return CafeBranch::whereHas('staff', fn ($q) => $q->where('user_id', $userId)->active())
            ->orderBy('branch_name')
            ->get();
    }
}
