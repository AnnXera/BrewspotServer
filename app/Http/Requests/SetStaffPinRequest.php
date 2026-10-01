<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\StaffRequestHelpers;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/{owner|manager}/branches/{branchUuid}/staff/{userUuid}/pin
 * Sets a new PIN and unlocks it if it was locked.
 */
class SetStaffPinRequest extends FormRequest
{
    use StaffRequestHelpers;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pin' => ['required', ...self::PIN_RULE],
        ];
    }

    public function messages(): array
    {
        return [
            'pin.regex' => 'PIN must be exactly 6 digits.',
        ];
    }
}
