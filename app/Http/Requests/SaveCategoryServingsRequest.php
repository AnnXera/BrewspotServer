<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * PUT /api/{owner|manager}/branches/{branchUuid}/servings/categories/{categoryUuid}/items
 */
class SaveCategoryServingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items'                  => ['required', 'array', 'min:1', 'max:200'],
            'items.*.menu_item_uuid' => ['required', 'string', 'max:64'],
            'items.*.daily_limit'    => ['required', 'integer', 'min:0', 'max:100000'],
            'items.*.enabled'        => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.*.daily_limit.integer' => 'Daily limit must be a whole number.',
            'items.*.daily_limit.min'     => 'Daily limit can\'t be negative.',
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
