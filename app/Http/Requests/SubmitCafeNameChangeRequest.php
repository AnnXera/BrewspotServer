<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * An owner asking to rename their cafe: the new name plus one updated BIR COR
 * per branch, sent as documents[<branch uuid>][file] and [registered_at].
 * Which branches must be present is checked in CafeUpdateService.
 */
class SubmitCafeNameChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('new_name'))) {
            $this->merge(['new_name' => trim(preg_replace('/\s+/', ' ', $this->input('new_name')))]);
        }
    }

    public function rules(): array
    {
        return [
            'new_name'                  => ['required', 'string', 'min:2', 'max:150'], // same limit as registration
            'documents'                 => ['required', 'array'],
            'documents.*.file'          => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'documents.*.registered_at' => ['required', 'date', 'before_or_equal:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'new_name.required'                  => 'Enter the new cafe name.',
            'new_name.min'                       => 'The cafe name is too short.',
            'new_name.max'                       => 'The cafe name must not exceed 150 characters.',
            'documents.required'                 => 'Upload the updated COR for every branch.',
            'documents.*.file.required'          => 'Upload the updated COR for this branch.',
            'documents.*.file.mimes'             => 'The COR must be a jpg, png or pdf file.',
            'documents.*.file.max'               => 'The COR must not exceed 5MB.',
            'documents.*.registered_at.required' => 'Enter the date on the updated COR.',
            'documents.*.registered_at.before_or_equal' => 'The COR date cannot be in the future.',
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
