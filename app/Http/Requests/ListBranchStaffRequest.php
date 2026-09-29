<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\StaffRequestHelpers;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/{owner|manager}/branches/{branchUuid}/staff
 */
class ListBranchStaffRequest extends FormRequest
{
    use StaffRequestHelpers;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search'   => ['nullable', 'string', 'max:100'],
            'status'   => ['nullable', 'string', 'in:active,inactive,suspended,terminated'],
            'role'     => ['nullable', 'string', Rule::in(User::EMPLOYEE_ROLES)],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
            'page'     => ['nullable', 'integer', 'min:1'],
        ];
    }
}
