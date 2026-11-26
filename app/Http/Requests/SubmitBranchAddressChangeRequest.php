<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * An owner asking to move a branch: the new address plus the updated BIR COR
 * showing it. Whether the branch may ask right now is checked in CafeUpdateService.
 */
class SubmitBranchAddressChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('new_address'))) {
            $this->merge(['new_address' => trim(preg_replace('/\s+/', ' ', $this->input('new_address')))]);
        }
    }

    public function rules(): array
    {
        return [
            'new_address'   => ['required', 'string', 'min:5', 'max:500'],
            'file'          => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'registered_at' => ['required', 'date', 'before_or_equal:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'new_address.required'          => 'Enter the new branch address.',
            'new_address.min'               => 'The address is too short.',
            'new_address.max'               => 'The address must not exceed 500 characters.',
            'file.required'                 => 'Upload the updated COR showing the new address.',
            'file.mimes'                    => 'The COR must be a jpg, png or pdf file.',
            'file.max'                      => 'The COR must not exceed 5MB.',
            'registered_at.required'        => 'Enter the date on the updated COR.',
            'registered_at.before_or_equal' => 'The COR date cannot be in the future.',
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
