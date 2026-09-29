<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\StaffRequestHelpers;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/pos/device/staff/{userUuid}/change-pin
 * Replaces a temporary PIN on the register, then unlocks.
 */
class ChangePinOnDeviceRequest extends FormRequest
{
    use StaffRequestHelpers;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_pin' => ['required', 'string', 'max:6'],
            'new_pin'     => ['required', 'confirmed', ...self::PIN_RULE],
        ];
    }

    public function messages(): array
    {
        return [
            'new_pin.regex'     => 'PIN must be 4 to 6 digits.',
            'new_pin.confirmed' => 'The two new PINs don\'t match.',
        ];
    }
}
