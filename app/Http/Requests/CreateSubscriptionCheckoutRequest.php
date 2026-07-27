<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class CreateSubscriptionCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plan_uuid' => ['required', 'string', 'exists:subscription_plans,uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'plan_uuid.required' => 'A subscription plan is required.',
            'plan_uuid.exists'   => 'Subscription plan not found.',
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