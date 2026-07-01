<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateSubscriptionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $planId = $this->route('uuid');

        return [
            'sub_name'      => [
                'sometimes', 'required', 'string', 'max:150',
                'unique:subscription_plans,sub_name,' . $planId . ',uuid',
            ],
            'price'         => ['sometimes', 'required', 'numeric', 'min:0'],
            'max_branches'  => ['sometimes', 'required', 'integer', 'min:1'],
            'description'   => ['nullable', 'string'],
            'duration_days' => ['sometimes', 'required', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'sub_name.required' => 'Plan name is required.',
            'sub_name.unique'   => 'A plan with this name already exists.',
            'price.numeric'     => 'Price must be a number.',
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