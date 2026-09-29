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
            // Managers log in to the dashboard, so they need an email.
            // Cashiers only use the POS, so it's optional for them.
            'email'          => ['nullable', 'required_if:role,Manager', 'email', 'max:255', 'unique:users,email'],
            'phone_number'   => ['nullable', 'string', 'max:20'],
            'address'        => ['nullable', 'string', 'max:255'],
            'role'           => ['required', 'string', 'in:Manager,Cashier'],
            'hired_at'       => ['nullable', 'date'],
            // Cashiers can't do anything without a PIN; managers may set theirs later.
            'pin'            => ['nullable', 'required_if:role,Cashier', ...self::PIN_RULE],
            'branch_uuids'   => ['required', 'array', 'min:1'],
            'branch_uuids.*' => ['string', 'exists:cafe_branches,uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'firstname.required'    => 'First name is required.',
            'lastname.required'     => 'Last name is required.',
            'email.required_if'     => 'Email is required for managers.',
            'email.unique'          => 'This email is already in use.',
            'role.required'         => 'Role is required.',
            'role.in'               => 'Role must be Manager or Cashier.',
            'pin.required_if'       => 'A PIN is required for cashiers.',
            'pin.regex'             => 'PIN must be 4 to 6 digits.',
            'branch_uuids.required' => 'Select at least one branch.',
            'branch_uuids.*.exists' => 'One or more selected branches were not found.',
        ];
    }
}
