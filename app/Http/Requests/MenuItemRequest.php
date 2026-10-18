<?php

namespace App\Http\Requests;

use App\Models\Ingredient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class MenuItemRequest extends FormRequest
{
    public const MAX_SIZES = 6;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Sent as JSON strings alongside the picture in multipart form data.
        foreach (['recipes', 'sizes', 'addon_uuids'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => json_decode($this->input($field), true)]);
            }
        }
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('patch') || $this->isMethod('put');

        return [
            'category_uuid' => ['nullable', 'string'],
            'menu_name'     => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:150'],
            'description'   => ['nullable', 'string'],
            // With sizes, the price comes from the sizes (lowest one).
            'base_price'    => [$isUpdate ? 'sometimes' : 'required_without:sizes', 'nullable', 'numeric', 'min:0'],
            'is_available'  => ['sometimes', 'boolean'],
            'picture'       => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            
            'recipes'                     => [$isUpdate ? 'sometimes' : 'required', 'array', 'min:1'],
            // Pick an existing ingredient by uuid, or type a name (matched
            // ignoring case, or created). Unit must match the ingredient's —
            // both checked in MenuItemService.
            'recipes.*.ingredient_uuid'   => ['nullable', 'string'],
            'recipes.*.ingredient_name'   => ['required_without:recipes.*.ingredient_uuid', 'nullable', 'string', 'max:100'],
            'recipes.*.quantity'          => ['required', 'numeric', 'min:0'],
            'recipes.*.unit'              => ['required', 'string', Rule::in(Ingredient::UNITS)],
            // Index into `sizes` (or the item's current sizes); null = every size.
            'recipes.*.size_index'        => ['nullable', 'integer', 'min:0'],

            // [] = no sizes. A size sent back with its uuid is updated, not replaced.
            'sizes'         => ['sometimes', 'nullable', 'array', 'max:' . self::MAX_SIZES, function ($attribute, $value, $fail) {
                if (is_array($value) && count($value) === 1) {
                    $fail('Add at least two sizes, or turn sizes off and use one price.');
                }
            }],
            'sizes.*.uuid'  => ['nullable', 'string'],
            'sizes.*.name'  => ['required', 'string', 'max:50', 'distinct:ignore_case'],
            'sizes.*.price' => ['required', 'numeric', 'min:0'],

            'addon_uuids'   => ['sometimes', 'nullable', 'array'],
            'addon_uuids.*' => ['string', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'recipes.required' => 'At least one recipe is required for a menu item.',
            'recipes.min'      => 'At least one recipe is required for a menu item.',
            'recipes.*.ingredient_name.required_without' => 'Ingredient name is required.',
            'recipes.*.unit.in'                  => 'Choose a unit from the list.',
            'recipes.*.quantity.required'        => 'Quantity is required.',
            'recipes.*.unit.required'            => 'Unit is required.',
            'base_price.required_without'        => 'Price is required.',
            'sizes.max'                          => 'An item can have at most ' . self::MAX_SIZES . ' sizes.',
            'sizes.*.name.required'              => 'Size name is required.',
            'sizes.*.name.distinct'              => 'Two sizes have the same name.',
            'sizes.*.price.required'             => 'Size price is required.',
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
