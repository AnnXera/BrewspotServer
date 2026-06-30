<?php

namespace App\Http\Requests;

use App\Models\UserDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('user')?->user_id ?? null;

        return [
            // User Details
            'firstname'             => ['required', 'string', 'max:100'],
            'middlename'            => ['nullable', 'string', 'max:100'],
            'lastname'              => ['required', 'string', 'max:100'],
            'phone_number'          => ['required', 'string', 'max:20'],
            'username'              => [
                'required',
                'string',
                'max:50',
                'unique:users,username,' . $userId . ',user_id',
            ],

            // User Document
            'id_type'               => ['required', 'string', 'in:' . implode(',', array_keys(UserDocument::$allowedIds))],
            'file'                  => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],

            // Cafe Details
            'cafe_name'             => ['required', 'string', 'max:150'],
            'cafe_doc_type'         => ['required', 'string', 'in:DTI,SEC'],
            'dti_sec_file'          => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],

            // Main Branch Details
            'cafe_picture'          => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'branch_name'           => ['required', 'string', 'max:150'],
            'cafe_email'            => ['required', 'email', 'max:255', 'unique:cafe_branches,cafe_email'],
            'cafe_phonenumber'      => ['required', 'string', 'max:20'],
            'address'               => ['required', 'string'],
            'bir_file'              => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'mayors_permit_file'    => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'sanitary_permit_file'  => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            // User
            'firstname.required'            => 'First name is required.',
            'lastname.required'             => 'Last name is required.',
            'phone_number.required'         => 'Phone number is required.',
            'username.required'             => 'Username is required.',
            'username.unique'               => 'This username is already taken.',

            // User Document
            'id_type.required'              => 'ID type is required.',
            'id_type.in'                    => 'Invalid ID type selected.',
            'file.required'                 => 'A valid government ID file is required.',
            'file.mimes'                    => 'ID file must be jpg, jpeg, png, or pdf.',
            'file.max'                      => 'ID file must not exceed 5MB.',

            // Cafe
            'cafe_name.required'            => 'Cafe name is required.',
            'cafe_doc_type.required'        => 'Cafe document type is required.',
            'cafe_doc_type.in'              => 'Cafe document type must be DTI or SEC.',
            'dti_sec_file.required'         => 'DTI or SEC file is required.',
            'dti_sec_file.mimes'            => 'DTI/SEC file must be jpg, jpeg, png, or pdf.',
            'dti_sec_file.max'              => 'DTI/SEC file must not exceed 5MB.',

            // Branch
            'cafe_picture.mimes'            => 'Cafe picture must be jpg, jpeg, png, or webp.',
            'cafe_picture.max'              => 'Cafe picture must not exceed 2MB.',
            'branch_name.required'          => 'Branch name is required.',
            'cafe_email.required'           => 'Cafe email is required.',
            'cafe_email.unique'             => 'This cafe email is already in use.',
            'cafe_phonenumber.required'     => 'Cafe phone number is required.',
            'address.required'              => 'Branch address is required.',
            'bir_file.required'             => 'BIR file is required.',
            'bir_file.mimes'                => 'BIR file must be jpg, jpeg, png, or pdf.',
            'bir_file.max'                  => 'BIR file must not exceed 5MB.',
            'mayors_permit_file.required'   => 'Mayor\'s permit file is required.',
            'mayors_permit_file.mimes'      => 'Mayor\'s permit must be jpg, jpeg, png, or pdf.',
            'mayors_permit_file.max'        => 'Mayor\'s permit must not exceed 5MB.',
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