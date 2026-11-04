<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RespondsWithFirstJsonError;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/user/profile
 */
class UpdateUserProfileRequest extends FormRequest
{
    use RespondsWithFirstJsonError;

    public function rules(): array
    {
        return [
            'firstname'    => ['required', 'string', 'max:100'],
            'middlename'   => ['nullable', 'string', 'max:100'],
            'lastname'     => ['required', 'string', 'max:100'],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'address'      => ['nullable', 'string', 'max:255'],
        ];
    }
}
