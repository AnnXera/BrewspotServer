<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

use Illuminate\Validation\Rule;

class UpdateBranchRequest extends FormRequest
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
                if (str_starts_with($digits, '63')) {
                    $digits = '0' . substr($digits, 2);
                }
                $this->merge(['cafe_phonenumber' => $digits]);
            }
        }
    }

    public function rules(): array
    {
        $branchUuid = $this->route('uuid');

        return [
            'branch_name'          => ['nullable', 'string', 'max:150'],
            'cafe_picture'         => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'cafe_email'           => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('cafe_branches', 'cafe_email')
                    ->whereNull('deleted_at')
                    ->ignore($branchUuid, 'uuid'),
            ],
            'cafe_phonenumber'     => [
                'nullable',
                'string',
                'max:20',
                'regex:/^09\d{9}$/',
                Rule::unique('cafe_branches', 'cafe_phonenumber')
                    ->whereNull('deleted_at')
                    ->ignore($branchUuid, 'uuid'),
                Rule::unique('users', 'phone_number'),
            ],
            'address'              => ['nullable', 'string'],
            'status'               => ['nullable', 'string', 'in:active,inactive'],
            'bir_file'             => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'mayors_permit_file'   => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'sanitary_permit_file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'branch_name.max'               => 'Branch name must not exceed 150 characters.',
            'cafe_picture.mimes'            => 'Branch picture must be jpg, jpeg, png, or webp.',
            'cafe_picture.max'              => 'Branch picture must not exceed 2MB.',
            'cafe_email.email'              => 'Please provide a valid email address.',
            'cafe_email.unique'             => 'This branch email is already in use.',
            'cafe_phonenumber.regex'        => 'Branch phone number must start with 09 and be 11 digits long (e.g., 09123456789).',
            'cafe_phonenumber.unique'       => 'This branch phone number is already in use.',
            'status.in'                     => 'Status must be either active or inactive.',
            'bir_file.mimes'                => 'BIR file must be jpg, jpeg, png, or pdf.',
            'bir_file.max'                  => 'BIR file must not exceed 5MB.',
            'mayors_permit_file.mimes'      => "Mayor's permit must be jpg, jpeg, png, or pdf.",
            'mayors_permit_file.max'        => "Mayor's permit must not exceed 5MB.",
            'sanitary_permit_file.mimes'    => 'Sanitary permit must be jpg, jpeg, png, or pdf.',
            'sanitary_permit_file.max'      => 'Sanitary permit must not exceed 5MB.',
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
