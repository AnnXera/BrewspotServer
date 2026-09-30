<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\StaffRequestHelpers;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'phone_number'   => ['required', ...self::PHONE_RULE],
            'address'        => ['required', 'string', 'max:255'],
            'role'           => ['required', 'string', Rule::in(User::EMPLOYEE_ROLES)],
            'hired_at'       => ['nullable', 'date'],
            // Only cashiers get a PIN here. Managers choose their own during
            // password setup; Staff are records only — no PIN.
            'pin'            => ['exclude_unless:role,Cashier', 'required', ...self::PIN_RULE],
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
            'phone_number.required' => 'Phone number is required.',
            'phone_number.regex'    => self::PHONE_REGEX_MESSAGE,
            'address.required'      => 'Address is required.',
            'role.in'               => 'Position must be Manager, Cashier or Staff.',
            'pin.required'          => 'A PIN is required.',
            'pin.regex'             => 'PIN must be exactly 4 digits.',
            'branch_uuids.required' => 'Select at least one branch.',
            'branch_uuids.*.exists' => 'One or more selected branches were not found.',
        ];
    }
}
