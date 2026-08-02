<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class MenuCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $cafe     = $this->user()->cafes()->first();
        $isUpdate = $this->isMethod('patch') || $this->isMethod('put');

        return [
            'name' => [
                $isUpdate ? 'sometimes' : 'required',
                'required',
                'string',
                'max:150',
                Rule::unique('menu_categories', 'name')
                    ->where('cafe_id', $cafe?->cafe_id)
                    ->ignore($this->route('uuid'), 'uuid'),
            ],
            'is_available' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Category name is required.',
            'name.unique'    => 'A category with this name already exists for your cafe.',
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