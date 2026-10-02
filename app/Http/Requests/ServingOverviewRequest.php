<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Read-only servings dashboard queries:
 *   GET /api/{owner|manager}/branches/{branchUuid}/servings/categories
 *   GET /api/{owner|manager}/branches/{branchUuid}/servings/ingredients-used
 *   GET /api/{owner|manager}/branches/{branchUuid}/servings/log
 */
class ServingOverviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $sorts = match ($this->route()?->getActionMethod()) {
            'categories'      => ['name', 'remaining_desc', 'remaining_asc'],
            'ingredientsUsed' => ['name', 'quantity_desc', 'quantity_asc'],
            default           => [],
        };

        return [
            'search'   => ['nullable', 'string', 'max:100'],
            'sort'     => ['nullable', Rule::in($sorts)],
            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
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
