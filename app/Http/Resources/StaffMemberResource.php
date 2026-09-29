<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A staff member as seen from one branch (the Employees tab of a branch).
 *
 * Expects the User with `role` and `staffAssignments` loaded, where
 * staffAssignments is already filtered to the branch being viewed.
 * `can_manage` tells the UI whether to show Edit / Terminate / Reset PIN
 * for the person viewing (managers see other managers read-only).
 */
class StaffMemberResource extends JsonResource
{
    private const DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    public function __construct($resource, private readonly bool $canManage = false)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $assignment = $this->relationLoaded('staffAssignments') ? $this->staffAssignments->first() : null;

        return [
            'uuid'           => $this->uuid,
            'firstname'      => $this->firstname,
            'middlename'     => $this->middlename,
            'lastname'       => $this->lastname,
            'email'          => $this->email,
            'phone_number'   => $this->phone_number,
            'address'        => $this->address,
            'role'           => $this->whenLoaded('role', fn () => $this->role->role_name),
            'account_status' => $this->status,
            'pin_set'        => $this->hasPin(),
            'pin_locked'     => $this->isPinLocked(),
            'can_manage'     => $this->canManage,
            'assignment'     => $assignment ? [
                'staff_uuid'        => $assignment->uuid,
                'branch_uuid'       => $assignment->branch?->uuid,
                'branch_name'       => $assignment->branch?->branch_name,
                'employment_status' => $assignment->employment_status,
                'hired_at'          => $assignment->hired_at?->toDateString(),
                'terminated_at'     => $assignment->terminated_at?->toISOString(),
            ] : null,
            'schedule' => $assignment && $assignment->relationLoaded('schedules')
                ? $assignment->schedules->map(fn ($day) => [
                    'day_of_week' => $day->day_of_week,
                    'day_name'    => self::DAY_NAMES[$day->day_of_week] ?? null,
                    'is_day_off'  => $day->is_day_off,
                    'start_time'  => $day->start_time ? substr($day->start_time, 0, 5) : null,
                    'end_time'    => $day->end_time ? substr($day->end_time, 0, 5) : null,
                ])->values()
                : [],
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
