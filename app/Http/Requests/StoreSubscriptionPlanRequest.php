<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreSubscriptionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sub_name'      => ['required', 'string', 'max:150', 'unique:subscription_plans,sub_name'],
            'price'         => ['required', 'numeric', 'min:0'],
            'max_branches'  => ['required', 'integer', 'min:1'], 
            'description'   => ['nullable', 'string'],
            'duration_days' => ['required', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'sub_name.required'      => 'Plan name is required.',
            'sub_name.unique'        => 'A plan with this name already exists.',
            'price.required'         => 'Price is required.',
            'price.numeric'          => 'Price must be a number.',
            'max_branches.required'  => 'Max branches is required.',
            'duration_days.required' => 'Duration (in days) is required.',
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