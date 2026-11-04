<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

/**
 * Same 422 body as RespondsWithJsonErrors ({ success, message, errors }), but
 * `message` is Laravel's own summary ("The email field is required. (and 1 more
 * error)") rather than a generic line. For screens that show `message` to the user.
 */
trait RespondsWithFirstJsonError
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => (new ValidationException($validator))->getMessage(),
                'errors'  => $validator->errors(),
            ], 422)
        );
    }
}
