<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps a User (role Manager/Cashier) together with their cafe_staff
 * assignment rows, so one staff member spanning multiple branches
 * renders as a single record with a `branches` array.
 */
class CafeStaffResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'           => $this->uuid,
            'firstname'      => $this->firstname,
            'middlename'     => $this->middlename,
            'lastname'       => $this->lastname,
            'email'          => $this->email,
            'phone_number'   => $this->phone_number,
            'role'           => $this->whenLoaded('role', fn () => $this->role->role_name),
            'account_status' => $this->status,
            'branches'       => $this->whenLoaded('staffAssignments', fn () =>
                $this->staffAssignments->map(fn ($assignment) => [
                    'staff_uuid'        => $assignment->uuid,
                    'branch_uuid'       => $assignment->branch->uuid,
                    'branch_name'       => $assignment->branch->branch_name,
                    'position'          => $assignment->position,
                    'employment_status' => $assignment->employment_status,
                    'hired_at'          => $assignment->hired_at?->toDateString(),
                ])
            ),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}