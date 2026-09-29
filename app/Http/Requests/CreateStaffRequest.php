<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\StaffRequestHelpers;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/owner/staff — owner creates a staff member across one or more branches.
 */
class CreateStaffRequest extends FormRequest
{
    use StaffRequestHelpers;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizePhoneNumber();
    }

    public function rules(): array
    {
        return [
            'firstname'      => ['required', 'string', 'max:100'],
            'middlename'     => ['nullable', 'string', 'max:100'],
            'lastname'       => ['required', 'string', 'max:100'],
            // Required for every employee (contact). Only managers use it to log in.
            'email'          => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone_number'   => ['nullable', 'string', 'max:20'],
            'address'        => ['nullable', 'string', 'max:255'],
            'role'           => ['required', 'string', 'in:Manager,Cashier'],
            'hired_at'       => ['nullable', 'date'],
            // Both roles need a PIN. For a manager it's temporary: they must
            // replace it the first time they use it (see StaffPinService).
            'pin'            => ['required', ...self::PIN_RULE],
            'branch_uuids'   => ['required', 'array', 'min:1'],
            'branch_uuids.*' => ['string', 'exists:cafe_branches,uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'firstname.required'    => 'First name is required.',
            'lastname.required'     => 'Last name is required.',
            'email.required'        => 'Email is required.',
            'email.unique'          => 'This email is already in use.',
            'role.required'         => 'Role is required.',
            'role.in'               => 'Role must be Manager or Cashier.',
            'pin.required'          => 'A PIN is required.',
            'pin.regex'             => 'PIN must be 4 to 6 digits.',
            'branch_uuids.required' => 'Select at least one branch.',
            'branch_uuids.*.exists' => 'One or more selected branches were not found.',
        ];
    }
}
