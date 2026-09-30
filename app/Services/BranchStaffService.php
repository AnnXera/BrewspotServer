<?php

namespace App\Services;

use App\Contracts\MailAdapterInterface;
use App\Http\Resources\StaffMemberResource;
use App\Mail\StaffAccountCreatedMail;
use App\Models\CafeBranch;
use App\Models\CafeStaff;
use App\Models\User;
use App\Repository\CafeStaffRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Employee management inside one branch, used by both the owner dashboard
 * and the manager dashboard. The branch has already been authorised by the
 * `branch.access` middleware; this class decides what the actor may do to
 * a given person:
 *
 *   Owner   — create/edit/terminate Managers and Cashiers, change roles.
 *   Manager — create/edit/terminate Cashiers only. Managers are read-only.
 *
 * Results carry an `http` key that the controller turns into the status code.
 */
class BranchStaffService
{
    private const DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    public function __construct(
        private readonly CafeStaffRepository $repo,
        private readonly StaffPinService $pins,
        private readonly MailAdapterInterface $mailer
    ) {}

    /**
     * @param  array{search?: ?string, status?: ?string, role?: ?string}  $filters
     */
    public function list(User $actor, CafeBranch $branch, array $filters, int $perPage = 15): array
    {
        $staff = $this->repo->listByBranch($branch->branch_id, $filters, $perPage);

        return [
            'success' => true,
            'staff'   => $staff->through(fn (User $user) => new StaffMemberResource($user, $this->canManage($actor, $user))),
        ];
    }

    public function stats(CafeBranch $branch): array
    {
        return [
            'success' => true,
            'stats'   => $this->repo->countByBranch($branch->branch_id),
        ];
    }

    public function show(User $actor, CafeBranch $branch, string $userUuid): array
    {
        $target = $this->repo->findStaffUserAtBranch($userUuid, $branch->branch_id);

        if (! $target) {
            return $this->notFound();
        }

        return [
            'success' => true,
            'staff'   => new StaffMemberResource($target, $this->canManage($actor, $target)),
        ];
    }

    public function create(User $actor, CafeBranch $branch, array $payload): array
    {
        if ($actor->isManager() && $payload['role'] === 'Manager') {
            return $this->forbidden('Managers can only add cashiers and staff. Ask the owner to add a manager.');
        }

        $role = $this->repo->findRoleByName($payload['role']);

        if (! $role) {
            return ['success' => false, 'http' => 422, 'message' => 'Invalid role selected.'];
        }

        $result = [];

        try {
            DB::transaction(function () use ($actor, $branch, $payload, $role, &$result) {
                $staffUser = $this->repo->createStaffUser($payload, $role);

                // Cashiers only — managers set their own PIN during password
                // setup, and Staff have none (the request drops it for both).
                if (isset($payload['pin'])) {
                    $this->pins->setPinFor($staffUser, $payload['pin'], $actor);
                }

                $assignment = $this->repo->assignToBranch(
                    $staffUser->user_id,
                    $branch->branch_id,
                    $payload['hired_at'] ?? null
                );

                if (! empty($payload['schedule'])) {
                    $this->repo->replaceSchedule($assignment, $payload['schedule']);
                }

                if ($role->role_name === 'Manager') {
                    $this->sendSetupEmail($staffUser, $role->role_name);
                }

                Log::channel('owner')->info('Staff account created (branch).', [
                    'actor_uuid'  => $actor->uuid,
                    'actor_role'  => $actor->roleName(),
                    'staff_uuid'  => $staffUser->uuid,
                    'role'        => $role->role_name,
                    'branch_uuid' => $branch->uuid,
                ]);

                $staffUser = $this->repo->findStaffUserAtBranch($staffUser->uuid, $branch->branch_id);

                $result = [
                    'success'  => true,
                    'http'     => 201,
                    'message'  => match ($role->role_name) {
                        'Manager' => 'Manager added. An email has been sent so they can set up their password.',
                        'Cashier' => 'Cashier added. They can now sign in on this branch\'s register with their PIN.',
                        default   => 'Employee added.',
                    },
                    'staff'    => new StaffMemberResource($staffUser, true),
                    'warnings' => $this->scheduleWarnings($branch, $payload['schedule'] ?? []),
                ];
            });

            return $result;

        } catch (\Throwable $e) {
            Log::channel('owner')->error('Staff creation failed (branch).', [
                'actor_uuid'  => $actor->uuid,
                'branch_uuid' => $branch->uuid,
                'error'       => $e->getMessage(),
            ]);

            return ['success' => false, 'http' => 500, 'message' => 'Something went wrong while adding the employee. Please try again.'];
        }
    }

    public function update(User $actor, CafeBranch $branch, string $userUuid, array $payload): array
    {
        $target = $this->repo->findStaffUserAtBranch($userUuid, $branch->branch_id);

        if (! $target) {
            return $this->notFound();
        }

        if (! $this->canManage($actor, $target)) {
            return $this->forbidden('You can only edit cashiers and staff. Ask the owner to change a manager\'s details.');
        }

        $newRole = $payload['role'] ?? $target->roleName();

        if ($newRole !== $target->roleName() && ! $actor->isOwner()) {
            return $this->forbidden('Only the owner can change an employee\'s role.');
        }

        $assignment = $target->staffAssignments->first();

        if ($assignment->employment_status === CafeStaff::STATUS_TERMINATED) {
            return ['success' => false, 'http' => 422, 'message' => 'This employee has been terminated at this branch and can no longer be edited.'];
        }

        DB::transaction(function () use ($actor, $branch, $target, $assignment, $payload, $newRole) {
            $userFields = array_intersect_key($payload, array_flip([
                'firstname', 'middlename', 'lastname', 'email', 'phone_number', 'address',
            ]));

            if (array_key_exists('email', $userFields) && $userFields['email'] !== $target->email) {
                // Owner/manager vouches for the new address, same as on creation.
                $userFields['email_verified_at'] = now();
            }

            $this->repo->updateUser($target, $userFields);

            $this->repo->updateAssignment($assignment, array_intersect_key($payload, array_flip([
                'hired_at', 'employment_status',
            ])));

            if ($newRole !== $target->roleName()) {
                $this->changeRole($target, $newRole);
            }

            if (! $assignment->isActive()) {
                $this->repo->endPosSessions([$assignment->staff_id]);
            }

            if (! $this->repo->hasActiveAssignments($target->user_id)) {
                $this->repo->revokeAccess($target);
            }

            Log::channel('owner')->info('Staff member updated.', [
                'actor_uuid'  => $actor->uuid,
                'actor_role'  => $actor->roleName(),
                'staff_uuid'  => $target->uuid,
                'branch_uuid' => $branch->uuid,
                'fields'      => array_keys($payload),
            ]);
        });

        $target = $this->repo->findStaffUserAtBranch($userUuid, $branch->branch_id);

        return [
            'success' => true,
            'message' => 'Employee updated.',
            'staff'   => new StaffMemberResource($target, $this->canManage($actor, $target)),
        ];
    }

    public function updateSchedule(User $actor, CafeBranch $branch, string $userUuid, array $days): array
    {
        $target = $this->repo->findStaffUserAtBranch($userUuid, $branch->branch_id);

        if (! $target) {
            return $this->notFound();
        }

        if (! $this->canManage($actor, $target)) {
            return $this->forbidden('You can only change schedules for cashiers and staff.');
        }

        $assignment = $target->staffAssignments->first();

        if ($assignment->employment_status === CafeStaff::STATUS_TERMINATED) {
            return ['success' => false, 'http' => 422, 'message' => 'This employee has been terminated at this branch.'];
        }

        $this->repo->replaceSchedule($assignment, $days);

        Log::channel('owner')->info('Staff schedule updated.', [
            'actor_uuid'  => $actor->uuid,
            'staff_uuid'  => $target->uuid,
            'branch_uuid' => $branch->uuid,
        ]);

        $target = $this->repo->findStaffUserAtBranch($userUuid, $branch->branch_id);

        return [
            'success'  => true,
            'message'  => 'Schedule saved.',
            'staff'    => new StaffMemberResource($target, true),
            'warnings' => $this->scheduleWarnings($branch, $days),
        ];
    }

    /**
     * Ends employment at this branch only. If the person has no other active
     * branch, their dashboard sessions are revoked too.
     */
    public function terminate(User $actor, CafeBranch $branch, string $userUuid): array
    {
        $target = $this->repo->findStaffUserAtBranch($userUuid, $branch->branch_id);

        if (! $target) {
            return $this->notFound();
        }

        if (! $this->canManage($actor, $target)) {
            return $this->forbidden('You can only terminate cashiers and staff. Ask the owner to terminate a manager.');
        }

        $assignment = $target->staffAssignments->first();

        if ($assignment->employment_status === CafeStaff::STATUS_TERMINATED) {
            return ['success' => false, 'http' => 422, 'message' => 'This employee is already terminated at this branch.'];
        }

        DB::transaction(function () use ($target, $assignment) {
            $this->repo->terminateAssignment($assignment);
            $this->repo->endPosSessions([$assignment->staff_id]);

            if (! $this->repo->hasActiveAssignments($target->user_id)) {
                $this->repo->revokeAccess($target);
            }
        });

        Log::channel('owner')->info('Staff terminated at branch.', [
            'actor_uuid'  => $actor->uuid,
            'actor_role'  => $actor->roleName(),
            'staff_uuid'  => $target->uuid,
            'branch_uuid' => $branch->uuid,
        ]);

        return ['success' => true, 'message' => 'Employee terminated at this branch.'];
    }

    public function setPin(User $actor, CafeBranch $branch, string $userUuid, string $pin): array
    {
        $target = $this->repo->findStaffUserAtBranch($userUuid, $branch->branch_id);

        if (! $target) {
            return $this->notFound();
        }

        if (! $this->canManage($actor, $target)) {
            return $this->forbidden('You can only reset PINs for cashiers.');
        }

        if ($target->isStaff()) {
            return ['success' => false, 'http' => 422, 'message' => 'Staff don\'t use the register, so they don\'t have a PIN. Change their position first.'];
        }

        $this->pins->setPinFor($target, $pin, $actor);

        Log::channel('owner')->info('Staff PIN set/reset.', [
            'actor_uuid'  => $actor->uuid,
            'staff_uuid'  => $target->uuid,
            'branch_uuid' => $branch->uuid,
            'temporary'   => $target->mustChangePin(),
        ]);

        return [
            'success' => true,
            'message' => $target->mustChangePin()
                ? 'Temporary PIN set. The manager will be asked to choose a new one the first time they use it.'
                : 'PIN updated. It works on every branch this person is assigned to.',
        ];
    }

    /**
     * Branch switcher list for the manager dashboard.
     */
    public function managedBranches(User $manager): array
    {
        return [
            'success'  => true,
            'branches' => $this->repo->findManagedBranches($manager->user_id)->map(fn (CafeBranch $b) => [
                'uuid'        => $b->uuid,
                'branch_name' => $b->branch_name,
                'address'     => $b->address,
                'status'      => $b->status,
            ])->values(),
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function canManage(User $actor, User $target): bool
    {
        if ($actor->isOwner()) {
            return true;
        }

        // Managers handle everyone below them; other managers are owner-only.
        return $actor->isManager() && ($target->isCashier() || $target->isStaff());
    }

    /**
     * Role is account-wide, so this affects every branch the person is at.
     */
    private function changeRole(User $target, string $newRole): void
    {
        $role = $this->repo->findRoleByName($newRole);

        if ($newRole === 'Manager') {
            // A cashier never had a password; they need to set one to use the dashboard.
            $needsSetup = ! $target->password_hash;

            $this->repo->updateUser($target, [
                'role_id' => $role->role_id,
                'status'  => $needsSetup ? 'pending_setup' : $target->status,
            ]);

            // Their cashier PIN was set by the owner/a manager, so others may
            // know it. As a manager it approves refunds — make it temporary.
            if ($target->hasPin()) {
                $target->forceFill(['pin_must_change' => true])->save();
            }

            if ($needsSetup) {
                $this->sendSetupEmail($target, 'Manager');
            }

            return;
        }

        // → Cashier or Staff: no more dashboard access.
        $this->repo->updateUser($target, [
            'role_id'       => $role->role_id,
            'password_hash' => null,
            'status'        => 'active',
        ]);

        $target->tokens()->delete();

        // → Staff: records only, so the PIN goes and any register session ends.
        if ($newRole === 'Staff') {
            $target->forceFill([
                'pin_hash'            => null,
                'pin_failed_attempts' => 0,
                'pin_locked_at'       => null,
                'pin_must_change'     => false,
            ])->save();

            $this->repo->endPosSessions($target->staffAssignments()->pluck('staff_id')->all());
        }
    }

    private function sendSetupEmail(User $user, string $roleName): void
    {
        $this->mailer->sendMailable($user->email, new StaffAccountCreatedMail(
            firstname: $user->firstname,
            roleName: $roleName,
            staffUuid: $user->uuid,
        ));
    }

    /**
     * Non-blocking warnings when a shift falls outside the cafe's opening hours.
     *
     * @return array<int, string>
     */
    private function scheduleWarnings(CafeBranch $branch, array $days): array
    {
        if (empty($days)) {
            return [];
        }

        $hours    = $this->repo->findOpeningHours($branch->cafe_id);
        $warnings = [];

        foreach ($days as $day) {
            if (! empty($day['is_day_off'])) {
                continue;
            }

            $dayName = self::DAY_NAMES[$day['day_of_week']];
            $open    = $hours->get($dayName);

            if (! $open) {
                continue;
            }

            if ($open->is_closed) {
                $warnings[] = "{$dayName}: the cafe is closed, but a shift is scheduled.";
                continue;
            }

            $opens  = substr((string) $open->open_time, 0, 5);
            $closes = substr((string) $open->close_time, 0, 5);

            if ($opens && $closes && ($day['start_time'] < $opens || $day['end_time'] > $closes)) {
                $warnings[] = "{$dayName}: shift {$day['start_time']}-{$day['end_time']} is outside opening hours ({$opens}-{$closes}).";
            }
        }

        return $warnings;
    }

    private function notFound(): array
    {
        return ['success' => false, 'http' => 404, 'message' => 'Employee not found at this branch.'];
    }

    private function forbidden(string $message): array
    {
        return ['success' => false, 'http' => 403, 'message' => $message];
    }
}
