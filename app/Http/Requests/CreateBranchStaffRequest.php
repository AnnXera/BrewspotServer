<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\StaffRequestHelpers;
use Illuminate\Foundation\Http\FormRequest;
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
            'email'        => ['nullable', 'required_if:role,Manager', 'email', 'max:255', 'unique:users,email'],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'address'      => ['nullable', 'string', 'max:255'],
            'role'         => ['required', 'string', 'in:Manager,Cashier'],
            'hired_at'     => ['nullable', 'date'],
            'pin'          => ['nullable', 'required_if:role,Cashier', ...self::PIN_RULE],
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
            'email.required_if' => 'Email is required for managers.',
            'email.unique'      => 'This email is already in use.',
            'role.in'           => 'Role must be Manager or Cashier.',
            'pin.required_if'   => 'A PIN is required for cashiers.',
            'pin.regex'         => 'PIN must be 4 to 6 digits.',
        ];
    }
}
