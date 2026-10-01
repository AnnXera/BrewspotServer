<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\StaffRequestHelpers;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rules\Password;

class SetupPasswordRequest extends FormRequest
{
    use StaffRequestHelpers;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(8)
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
            // Managers only — PasswordSetupService requires it for them and
            // ignores it for everyone else.
            'pin' => ['nullable', 'confirmed', ...self::PIN_RULE],
        ];
    }

    public function messages(): array
    {
        return [
            'password.required'  => 'Password is required.',
            'password.confirmed' => 'Password confirmation does not match.',
            'pin.regex'          => 'PIN must be exactly 6 digits.',
            'pin.confirmed'      => 'The two PINs don\'t match.',
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422)
        );
    }
}