<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\StaffRequestHelpers;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/pos/device/staff/{userUuid}/unlock
 */
class UnlockPosRequest extends FormRequest
{
    use StaffRequestHelpers;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pin' => ['required', 'string', 'max:6'],
        ];
    }
}
