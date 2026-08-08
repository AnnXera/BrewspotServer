<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateOwnerStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Admin-driven transitions only. 'inactive' is owner self-service —
            // admins can never set it, and per ALLOWED_TRANSITIONS, an inactive
            // owner can only be reactivated by the owner themselves, not an admin.
            'status' => ['required', 'string', 'in:approved,rejected,suspended,active'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Status is required.',
            'status.in'       => 'Status must be one of: approved, rejected, suspended, active.',
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