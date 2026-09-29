<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\StaffRequestHelpers;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/pos/register — owner/manager registers this browser as a branch register.
 */
class RegisterPosDeviceRequest extends FormRequest
{
    use StaffRequestHelpers;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_uuid' => ['required', 'string'],
            'name'        => ['required', 'string', 'max:60'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Give this register a name, e.g. "Front Counter".',
        ];
    }
}
