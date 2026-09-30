<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\StaffRequestHelpers;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /api/{owner|manager}/branches/{branchUuid}/staff
 *
 * Whether the actor may create the requested role (managers → Cashier only)
 * is enforced in BranchStaffService, not here.
 */
class CreateBranchStaffRequest extends FormRequest
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
            'firstname'    => ['required', 'string', 'max:100'],
            'middlename'   => ['nullable', 'string', 'max:100'],
            'lastname'     => ['required', 'string', 'max:100'],
            // Required for every employee (contact). Only managers use it to log in.
            'email'        => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone_number' => ['required', ...self::PHONE_RULE],
            'address'      => ['required', 'string', 'max:255'],
            'role'         => ['required', 'string', Rule::in(User::EMPLOYEE_ROLES)],
            'hired_at'     => ['nullable', 'date'],
            // Only cashiers get a PIN here. Managers choose their own during
            // password setup; Staff are records only — no PIN.
            'pin'          => ['exclude_unless:role,Cashier', 'required', ...self::PIN_RULE],
            ...$this->scheduleRules(required: false),
        ];
    }

    public function after(): array
    {
        return [fn (Validator $validator) => $this->validateScheduleTimes($validator)];
    }

    public function messages(): array
    {
        return [
            'email.required'    => 'Email is required.',
            'email.unique'      => 'This email is already in use.',
            'phone_number.required' => 'Phone number is required.',
            'phone_number.regex'    => self::PHONE_REGEX_MESSAGE,
            'address.required'  => 'Address is required.',
            'role.in'           => 'Position must be Manager, Cashier or Staff.',
            'pin.required'      => 'A PIN is required.',
            'pin.regex'         => 'PIN must be exactly 4 digits.',
        ];
    }
}
