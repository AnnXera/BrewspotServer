<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class CreateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('phone_number') && is_string($this->input('phone_number'))) {
            $raw = trim($this->input('phone_number'));
            if (!empty($raw) && !preg_match('/[a-zA-Z]/', $raw)) {
                $digits = preg_replace('/\D/', '', $raw);
                if (str_starts_with($digits, '09')) {
                    $digits = substr($digits, 1);
                } elseif (str_starts_with($digits, '639')) {
                    $digits = substr($digits, 2);
                }
                $this->merge(['phone_number' => '+63' . $digits]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'firstname'      => ['required', 'string', 'max:100'],
            'middlename'     => ['nullable', 'string', 'max:100'],
            'lastname'       => ['required', 'string', 'max:100'],
            'email'          => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone_number'   => ['nullable', 'string', 'max:20'],
            'role'           => ['required', 'string', 'in:Manager,Cashier'],
            'position'       => ['nullable', 'string', 'max:150'],
            'branch_uuids'   => ['required', 'array', 'min:1'],
            'branch_uuids.*' => ['string', 'exists:cafe_branches,uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'firstname.required'    => 'First name is required.',
            'lastname.required'     => 'Last name is required.',
            'email.required'        => 'Email is required.',
            'email.unique'          => 'This email is already in use.',
            'role.required'         => 'Role is required.',
            'role.in'               => 'Role must be Manager or Cashier.',
            'branch_uuids.required' => 'Select at least one branch.',
            'branch_uuids.*.exists' => 'One or more selected branches were not found.',
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