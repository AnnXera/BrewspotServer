<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class CreateNativeSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plan_uuid'      => ['required', 'string', 'exists:subscription_plans,uuid'],
            'card_number'    => ['required', 'string'],
            'exp_month'      => ['required', 'string', 'size:2'],
            'exp_year'       => ['required', 'string', 'size:4'],
            'cvc'             => ['required', 'string'],
            'billing_name'    => ['required', 'string'],
            'billing_email'   => ['required', 'email'],
            'billing_phone'   => ['required', 'string'],
            'address_line1'   => ['nullable', 'string'],
            'address_line2'   => ['nullable', 'string'],
            'city'            => ['nullable', 'string'],
            'state'           => ['nullable', 'string'],
            'postal_code'     => ['nullable', 'string'],
            'country'         => ['nullable', 'string', 'size:2'],
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