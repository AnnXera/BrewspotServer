<?php

namespace App\Services;

use App\Contracts\MailAdapterInterface;
use App\Http\Resources\StaffMemberResource;
use App\Mail\StaffAccountCreatedMail;
use App\Models\ActivityLog;
use App\Models\CafeBranch;
use App\Models\CafeStaff;
use App\Models\User;
use App\Repository\CafeStaffRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class BranchStaffService
{
    private const DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    public function __construct(
        private readonly CafeStaffRepository $repo,
        private readonly StaffPinService $pins,
        private readonly MailAdapterInterface $mailer,
        private readonly ActivityLogService $activity
    ) {}

    /** Field labels for the audit log, in the order the edit form shows them. */
    private const FIELD_LABELS = [
        'firstname'         => 'first name',
        'middlename'        => 'middle name',
        'lastname'          => 'last name',
        'email'             => 'email',
        'phone_number'      => 'phone number',
        'address'           => 'address',
        'hired_at'          => 'hire date',
        'employment_status' => 'status',
        'role'              => 'position',
    ];

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

                if (isset($payload['pin'])) {
                    $this->pins->setPinFor($staffUser, $payload['pin'], $actor);
                }

                $assignment = $this->repo->assignToBranch(
                    $staffUser->user_id,
                    $branch->branch_id,
                    $payload['hired_at'] ?? now()->toDateString(),
                    $actor->user_id
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

            $this->logEmployee($actor, $branch, $result['staff']->resource, ActivityLog::EMPLOYEE_CREATED,
                fn (string $who) => "Added {$who}",
                ['hired_at' => $payload['hired_at'] ?? now()->toDateString()]
            );

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

        // A manager runs exactly one branch.
        if ($newRole === 'Manager' && $target->roleName() !== 'Manager'
            && $this->repo->countActiveAssignments($target->user_id) > 1) {
            return ['success' => false, 'http' => 422, 'message' => 'A manager can only be assigned to one branch. End this employee\'s other branch assignments first.'];
        }

        $assignment = $target->staffAssignments->first();

        if ($assignment->employment_status === CafeStaff::STATUS_TERMINATED) {
            return ['success' => false, 'http' => 422, 'message' => 'This employee has been terminated at this branch and can no longer be edited.'];
        }

        $before = [
            ...$target->only(['firstname', 'middlename', 'lastname', 'email', 'phone_number', 'address']),
            'hired_at'          => $assignment->hired_at?->toDateString(),
            'employment_status' => $assignment->employment_status,
            'role'              => $target->roleName(),
        ];

        DB::transaction(function () use ($actor, $branch, $target, $assignment, $payload, $newRole) {
            $userFields = array_intersect_key($payload, array_flip([
                'firstname', 'middlename', 'lastname', 'email', 'phone_number', 'address',
            ]));

            if (array_key_exists('email', $userFields) && $userFields['email'] !== $target->email) {
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

        $this->logEmployeeUpdate($actor, $branch, $target, $before, $payload);

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

        $beforeSchedule = $this->scheduleSummary($assignment->schedules->all());

        $this->repo->replaceSchedule($assignment, $days);

        $afterSchedule = $this->scheduleSummary($days);

        if ($beforeSchedule !== $afterSchedule) {
            $this->logEmployee($actor, $branch, $target, ActivityLog::EMPLOYEE_SCHEDULE_UPDATED,
                fn (string $who) => "Changed the schedule of {$who}",
                ['before' => $beforeSchedule, 'after' => $afterSchedule]
            );
        }

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

    public function terminate(User $actor, CafeBranch $branch, string $userUuid): array
    {
        $target = $this->repo->findStaffUserAtBranch($userUuid, $branch->branch_id);

        if (! $target) {
            return $this->notFound();
        }

        // Managers can edit cashiers and staff, but ending someone's employment is the owner's call.
        if (! $actor->isOwner()) {
            return $this->forbidden('Only the owner can terminate an employee.');
        }

        $assignment = $target->staffAssignments->first();

        if ($assignment->employment_status === CafeStaff::STATUS_TERMINATED) {
            return ['success' => false, 'http' => 422, 'message' => 'This employee is already terminated at this branch.'];
        }

        DB::transaction(function () use ($actor, $target, $assignment) {
            $this->repo->terminateAssignment($assignment, $actor->user_id);
            $this->repo->endPosSessions([$assignment->staff_id]);

            if (! $this->repo->hasActiveAssignments($target->user_id)) {
                $this->repo->revokeAccess($target);
            }
        });

        $this->logEmployee($actor, $branch, $target, ActivityLog::EMPLOYEE_TERMINATED, fn (string $who) => "Terminated {$who}");

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

        // Never the PIN itself.
        $this->logEmployee($actor, $branch, $target, ActivityLog::EMPLOYEE_PIN_RESET,
            fn (string $who) => "Set a new register PIN for {$who}",
            ['temporary' => $target->mustChangePin()]
        );

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

    /** @param  callable(string): string  $describe  gets "Juan Cruz (Cashier)" */
    private function logEmployee(User $actor, CafeBranch $branch, User $employee, string $action, callable $describe, array $meta = []): void
    {
        $who = trim("{$employee->firstname} {$employee->lastname}") . ' (' . ($employee->roleName() ?? 'Employee') . ')';

        $this->activity->employee($actor, $branch, $employee, $action, $describe($who), $meta);
    }

    /**
     * One entry listing what an edit changed. Contact details are named but their values
     * are left out; status, position and hire date keep before and after.
     */
    private function logEmployeeUpdate(User $actor, CafeBranch $branch, User $target, array $before, array $payload): void
    {
        $changes = [];

        foreach (self::FIELD_LABELS as $field => $label) {
            if (! array_key_exists($field, $payload) || (string) $payload[$field] === (string) ($before[$field] ?? '')) {
                continue;
            }

            $changes[$field] = in_array($field, ['hired_at', 'employment_status', 'role'], true)
                ? ['label' => $label, 'before' => $before[$field], 'after' => $payload[$field]]
                : ['label' => $label];
        }

        if (! $changes) {
            return;
        }

        $parts = collect($changes)->map(fn ($c) => isset($c['after']) ? "{$c['label']} {$c['before']} to {$c['after']}" : $c['label']);

        $this->logEmployee($actor, $branch, $target, ActivityLog::EMPLOYEE_UPDATED,
            fn (string $who) => "Edited {$who}: " . $parts->join(', '),
            ['changes' => $changes]
        );
    }

    /**
     * A schedule as "Monday" => "08:00-17:00" | "Day off", Sunday first, for comparing and logging.
     *
     * @param  array<int, array|\App\Models\StaffSchedule>  $days
     * @return array<string, string>
     */
    private function scheduleSummary(array $days): array
    {
        $summary = [];

        foreach (collect($days)->sortBy(fn ($d) => (int) data_get($d, 'day_of_week')) as $day) {
            $name = self::DAY_NAMES[(int) data_get($day, 'day_of_week')] ?? (string) data_get($day, 'day_of_week');

            $summary[$name] = data_get($day, 'is_day_off')
                ? 'Day off'
                : substr((string) data_get($day, 'start_time'), 0, 5) . '-' . substr((string) data_get($day, 'end_time'), 0, 5);
        }

        return $summary;
    }

    private function canManage(User $actor, User $target): bool
    {
        if ($actor->isOwner()) {
            return true;
        }

        return $actor->isManager() && ($target->isCashier() || $target->isStaff());
    }

    private function changeRole(User $target, string $newRole): void
    {
        $role = $this->repo->findRoleByName($newRole);

        if ($newRole === 'Manager') {
            $needsSetup = ! $target->password_hash;

            $this->repo->updateUser($target, [
                'role_id' => $role->role_id,
                'status'  => $needsSetup ? 'pending_setup' : $target->status,
            ]);

            if ($target->hasPin()) {
                $target->forceFill(['pin_must_change' => true])->save();
            }

            if ($needsSetup) {
                $this->sendSetupEmail($target, 'Manager');
            }

            return;
        }

            $this->repo->updateUser($target, [
            'role_id'       => $role->role_id,
            'password_hash' => null,
            'status'        => 'active',
        ]);

        $target->tokens()->delete();

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

            if ($open->is_24_hours) {
                continue;
            }

            $opens  = substr((string) $open->open_time, 0, 5);
            $closes = substr((string) $open->close_time, 0, 5);

            if (! $opens || ! $closes) {
                continue;
            }

            $todayEnd = $open->closesAfterMidnight() ? '24:00' : $closes;
            $fitsToday = $day['start_time'] >= $opens && $day['end_time'] <= $todayEnd;

            $yesterday = $hours->get(self::DAY_NAMES[($day['day_of_week'] + 6) % 7]);
            $fitsYesterdaysLateHours = $yesterday?->closesAfterMidnight()
                && $day['end_time'] <= substr((string) $yesterday->close_time, 0, 5);

            if (! $fitsToday && ! $fitsYesterdaysLateHours) {
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
