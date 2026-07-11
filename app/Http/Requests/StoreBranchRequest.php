<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_name'          => ['required', 'string', 'max:150'],
            'cafe_picture'         => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'cafe_email'           => ['required', 'email', 'max:255', 'unique:cafe_branches,cafe_email'],
            'cafe_phonenumber'     => ['required', 'string', 'max:20'],
            'address'              => ['required', 'string'],
            'bir_file'             => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'mayors_permit_file'   => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'sanitary_permit_file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
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
            'address.required'              => 'Branch address is required.',
            'bir_file.required'             => 'BIR file is required.',
            'bir_file.mimes'                => 'BIR file must be jpg, jpeg, png, or pdf.',
            'bir_file.max'                  => 'BIR file must not exceed 5MB.',
            'mayors_permit_file.required'   => "Mayor's permit file is required.",
            'mayors_permit_file.mimes'      => "Mayor's permit must be jpg, jpeg, png, or pdf.",
            'mayors_permit_file.max'        => "Mayor's permit must not exceed 5MB.",
            'sanitary_permit_file.required' => 'Sanitary permit file is required.',
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