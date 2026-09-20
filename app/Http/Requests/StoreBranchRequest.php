<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

use Illuminate\Validation\Rule;

class StoreBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('cafe_phonenumber') && is_string($this->input('cafe_phonenumber'))) {
            $raw = trim($this->input('cafe_phonenumber'));
            if (!preg_match('/[a-zA-Z]/', $raw)) {
                $digits = preg_replace('/\D/', '', $raw);
                if (str_starts_with($digits, '09')) {
                    $digits = substr($digits, 1);
                } elseif (str_starts_with($digits, '639')) {
                    $digits = substr($digits, 2);
                }
                $this->merge(['cafe_phonenumber' => '+63' . $digits]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'branch_name'          => ['required', 'string', 'max:150'],
            'cafe_picture'         => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'cafe_email'           => ['required', 'email', 'max:255', 'unique:cafe_branches,cafe_email'],
            'cafe_phonenumber'     => [
                'required',
                'string',
                'max:20',
                'regex:/^\+639\d{9}$/',
                Rule::unique('cafe_branches', 'cafe_phonenumber')->whereNull('deleted_at'),
                Rule::unique('users', 'phone_number'),
            ],
            'address'              => ['required', 'string'],
            'bir_file'             => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'bir_registered_at'    => ['required', 'date'],
            'bir_expired_at'       => ['nullable', 'date', 'after_or_equal:bir_registered_at'],
            'tin_number'           => ['required', 'string', 'max:50'],
            'vat'                  => ['required', 'string', 'in:vat-registered,non-vat'],
        ];
    }

    public function messages(): array
    {
        return [
            'branch_name.required'          => 'Branch name is required.',
            'cafe_picture.mimes'            => 'Branch picture must be jpg, jpeg, png, or webp.',
            'cafe_picture.max'              => 'Branch picture must not exceed 2MB.',
            'cafe_email.required'           => 'Branch email is required.',
            'cafe_email.unique'             => 'This branch email is already in use.',
            'cafe_phonenumber.required'     => 'Branch phone number is required.',
            'cafe_phonenumber.regex'        => 'Branch phone number must be 10 digits (e.g., 9123456789).',
            'cafe_phonenumber.unique'       => 'This branch phone number is already in use.',
            'address.required'              => 'Branch address is required.',
            'bir_file.required'             => 'BIR file is required.',
            'bir_file.mimes'                => 'BIR file must be jpg, jpeg, png, or pdf.',
            'bir_file.max'                  => 'BIR file must not exceed 5MB.',
            'bir_registered_at.required'    => 'BIR registered date is required.',
            'tin_number.required'           => 'TIN number is required.',
            'vat.required'                  => 'VAT type is required.',
            'vat.in'                        => 'VAT type must be either vat-registered or non-vat.',
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