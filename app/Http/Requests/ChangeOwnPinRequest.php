<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\StaffRequestHelpers;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/manager/pin — a manager sets their own PIN (used to approve voids/refunds).
 */
class ChangeOwnPinRequest extends FormRequest
{
    use StaffRequestHelpers;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'pin'              => ['required', ...self::PIN_RULE],
        ];
    }

    public function messages(): array
    {
        return [
            'pin.regex' => 'PIN must be exactly 4 digits.',
        ];
    }
}
