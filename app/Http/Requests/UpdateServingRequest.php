<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * PATCH /api/{owner|manager}/branches/{branchUuid}/servings/{servingUuid}
 */
class UpdateServingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expected_servings' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'is_sold_out'       => ['sometimes', 'boolean'],
            // Spoilage is logged per entry on the register (POST /api/pos/device/spoilage).
            'spoilage_qty'      => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->hasAny(['expected_servings', 'is_sold_out'])) {
                $validator->errors()->add('payload', 'Provide at least one of expected_servings or is_sold_out.');
            }
        });
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
