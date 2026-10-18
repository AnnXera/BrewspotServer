<?php

namespace App\Http\Requests;

use App\Models\Ingredient;
use App\Models\MenuAddon;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * POST  /api/owner/menu-addons
 * PATCH /api/owner/menu-addons/{uuid}
 *
 * Duplicate names are checked in MenuAddonService; recipe rows follow the
 * same rules as menu item recipes (RecipeResolver).
 */
class MenuAddonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('patch') || $this->isMethod('put');

        return [
            'name'         => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:100'],
            'price'        => [$isUpdate ? 'sometimes' : 'required', 'numeric', 'min:0'],
            // Most of this add-on one drink can have (2 = "double Oreo").
            'max_quantity' => ['sometimes', 'integer', 'min:1', 'max:' . MenuAddon::MAX_QUANTITY_LIMIT],
            'is_available' => ['sometimes', 'boolean'],

            // Ingredients one serving of the add-on uses. May be empty.
            'recipes'                   => ['sometimes', 'array'],
            'recipes.*.ingredient_uuid' => ['nullable', 'string'],
            'recipes.*.ingredient_name' => ['required_without:recipes.*.ingredient_uuid', 'nullable', 'string', 'max:100'],
            'recipes.*.quantity'        => ['required', 'numeric', 'min:0'],
            'recipes.*.unit'            => ['required', 'string', Rule::in(Ingredient::UNITS)],

            // Menu items that offer it.
            'item_uuids'   => ['sometimes', 'array'],
            'item_uuids.*' => ['string', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'                              => 'Add-on name is required.',
            'price.required'                             => 'Price is required.',
            'max_quantity.max'                           => 'At most ' . MenuAddon::MAX_QUANTITY_LIMIT . ' per drink.',
            'recipes.*.ingredient_name.required_without' => 'Ingredient name is required.',
            'recipes.*.unit.in'                          => 'Choose a unit from the list.',
            'recipes.*.quantity.required'                => 'Quantity is required.',
            'recipes.*.unit.required'                    => 'Unit is required.',
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
