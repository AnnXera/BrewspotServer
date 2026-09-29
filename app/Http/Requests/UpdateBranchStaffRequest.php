<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\StaffRequestHelpers;
use App\Models\CafeStaff;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/{owner|manager}/branches/{branchUuid}/staff/{userUuid}
 *
 * Every field is optional. Personal info applies to the person everywhere;
 * hired_at / employment_status apply to this branch only.
 * `role` is the "Position" in the UI (Manager / Cashier); changing it is
 * owner-only (enforced in BranchStaffService).
 */
class UpdateBranchStaffRequest extends FormRequest
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
            'firstname'         => ['sometimes', 'required', 'string', 'max:100'],
            'middlename'        => ['sometimes', 'nullable', 'string', 'max:100'],
            'lastname'          => ['sometimes', 'required', 'string', 'max:100'],
            'email'             => [
                'sometimes', 'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->route('userUuid'), 'uuid'),
            ],
            'phone_number'      => ['sometimes', 'nullable', 'string', 'max:20'],
            'address'           => ['sometimes', 'nullable', 'string', 'max:255'],
            'role'              => ['sometimes', 'string', 'in:Manager,Cashier'],
            'hired_at'          => ['sometimes', 'nullable', 'date'],
            'employment_status' => ['sometimes', 'string', Rule::in(CafeStaff::EDITABLE_STATUSES)],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique'         => 'This email is already in use.',
            'employment_status.in' => 'Status must be active, inactive or suspended. Use Terminate to end employment.',
        ];
    }
}
