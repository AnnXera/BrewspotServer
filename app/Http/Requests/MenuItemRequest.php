<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class MenuItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->recipes)) {
            $this->merge([
                'recipes' => json_decode($this->recipes, true)
            ]);
        }
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('patch') || $this->isMethod('put');

        return [
            'category_uuid' => [$isUpdate ? 'sometimes' : 'required', 'string'],
            'menu_name'     => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:150'],
            'description'   => ['nullable', 'string'],
            'base_price'    => [$isUpdate ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'is_available'  => ['sometimes', 'boolean'],
            'picture'       => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            
            'recipes'                     => [$isUpdate ? 'sometimes' : 'required', 'array', 'min:1'],
            'recipes.*.ingredient_name'   => ['required', 'string'],
            'recipes.*.quantity'          => ['required', 'numeric', 'min:0'],
            'recipes.*.unit'              => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'recipes.required' => 'At least one recipe is required for a menu item.',
            'recipes.min'      => 'At least one recipe is required for a menu item.',
            'recipes.*.ingredient_name.required' => 'Ingredient name is required.',
            'recipes.*.quantity.required'        => 'Quantity is required.',
            'recipes.*.unit.required'            => 'Unit is required.',
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
