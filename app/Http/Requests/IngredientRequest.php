<?php

namespace App\Http\Requests;

use App\Models\Ingredient;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * POST  /api/owner/ingredients
 * PATCH /api/owner/ingredients/{uuid}
 *
 * Duplicate names (ignoring case/spacing) and unit changes on ingredients
 * already used in recipes are checked in IngredientService.
 */
class IngredientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('patch') || $this->isMethod('put');

        return [
            'name'      => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:100'],
            'unit'      => [$isUpdate ? 'sometimes' : 'required', 'string', Rule::in(Ingredient::UNITS)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Ingredient name is required.',
            'unit.required' => 'Unit is required.',
            'unit.in'       => 'Choose a unit from the list.',
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
