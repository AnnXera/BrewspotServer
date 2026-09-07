<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Models\UserDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        if ($this->has('phone_number') && is_string($this->input('phone_number'))) {
            $raw = trim($this->input('phone_number'));
            if (!preg_match('/[a-zA-Z]/', $raw)) {
                $digits = preg_replace('/\D/', '', $raw);
                if (str_starts_with($digits, '63')) {
                    $digits = '0' . substr($digits, 2);
                }
                $merge['phone_number'] = $digits;
            }
        }
        if ($this->has('cafe_phonenumber') && is_string($this->input('cafe_phonenumber'))) {
            $raw = trim($this->input('cafe_phonenumber'));
            if (!preg_match('/[a-zA-Z]/', $raw)) {
                $digits = preg_replace('/\D/', '', $raw);
                if (str_starts_with($digits, '63')) {
                    $digits = '0' . substr($digits, 2);
                }
                $merge['cafe_phonenumber'] = $digits;
            }
        }
        if (!empty($merge)) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        $userId = User::where('uuid', $this->route('uuid'))->value('user_id');

        return [
            // User Details
            'firstname'             => ['required', 'string', 'max:100'],
            'middlename'            => ['nullable', 'string', 'max:100'],
            'lastname'              => ['required', 'string', 'max:100'],
            'phone_number'          => [
                'required',
                'string',
                'max:20',
                'regex:/^09\d{9}$/',
                'different:cafe_phonenumber',
                Rule::unique('users', 'phone_number')->ignore($userId, 'user_id'),
                Rule::unique('cafe_branches', 'cafe_phonenumber')->whereNull('deleted_at'),
            ],
            'owner_address'         => ['required', 'string', 'max:500'],
            'username'              => [
                'required',
                'string',
                'max:50',
                'unique:users,username,' . $userId . ',user_id',
            ],

            // User Document
            'id_type'               => ['required', 'string', 'in:' . implode(',', array_keys(UserDocument::$allowedIds))],
            'file'                  => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'file_back'             => [
                Rule::requiredIf(fn () => UserDocument::requiresBack($this->input('id_type'))),
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:5120',
            ],

            // Cafe Details
            'cafe_name'             => ['required', 'string', 'max:150'],
            'cafe_doc_type'         => ['required', 'string', 'in:DTI,SEC'],
            'dti_sec_file'          => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],

            // Main Branch Details
            'cafe_picture'          => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'branch_name'           => ['required', 'string', 'max:150'],
            'cafe_email'            => [
                'required',
                'email',
                'max:255',
                Rule::unique('cafe_branches', 'cafe_email')->whereNull('deleted_at'),
            ],
            'cafe_phonenumber'      => [
                'required',
                'string',
                'max:20',
                'regex:/^09\d{9}$/',
                'different:phone_number',
                Rule::unique('cafe_branches', 'cafe_phonenumber')->whereNull('deleted_at'),
                Rule::unique('users', 'phone_number')->ignore($userId, 'user_id'),
            ],
            'address'               => ['required', 'string'],
            'bir_file'              => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'mayors_permit_file'    => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'sanitary_permit_file'  => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $data = $validator->getData();
            $phoneRaw = (string) ($data['phone_number'] ?? $this->input('phone_number') ?? '');
            $cafePhoneRaw = (string) ($data['cafe_phonenumber'] ?? $this->input('cafe_phonenumber') ?? '');

            $phoneDigits = preg_replace('/\D/', '', $phoneRaw);
            $cafePhoneDigits = preg_replace('/\D/', '', $cafePhoneRaw);

            if (!empty($phoneRaw) && (!str_starts_with($phoneDigits, '09') || strlen($phoneDigits) !== 11)) {
                $validator->errors()->add('phone_number', 'Personal phone number must start with 09 and be 11 digits long (e.g., 09123456789).');
            }

            if (!empty($cafePhoneRaw) && (!str_starts_with($cafePhoneDigits, '09') || strlen($cafePhoneDigits) !== 11)) {
                $validator->errors()->add('cafe_phonenumber', 'Café phone number must start with 09 and be 11 digits long (e.g., 09123456789).');
            }

            $normPhone = preg_replace('/^63/', '0', $phoneDigits);
            $normCafePhone = preg_replace('/^63/', '0', $cafePhoneDigits);

            if ($normPhone !== '' && $normCafePhone !== '' && $normPhone === $normCafePhone) {
                $validator->errors()->add('cafe_phonenumber', 'The café phone number and personal contact number must be different.');
            }
        });
    }

    public function messages(): array
    {
        return [
            // User
            'firstname.required'            => 'First name is required.',
            'lastname.required'             => 'Last name is required.',
            'phone_number.required'         => 'Phone number is required.',
            'phone_number.regex'            => 'Personal phone number must start with 09 and be 11 digits long (e.g., 09123456789).',
            'phone_number.different'        => 'Personal phone number and café phone number must be different.',
            'phone_number.unique'           => 'This personal phone number is already registered or in use by a café.',
            'owner_address.required'        => 'Your address is required.',
            'username.required'             => 'Username is required.',
            'username.unique'               => 'This username is already taken.',

            // User Document
            'id_type.required'              => 'ID type is required.',
            'id_type.in'                    => 'Invalid ID type selected.',
            'file.required'                 => 'A valid government ID (front) file is required.',
            'file.mimes'                    => 'ID front file must be jpg, jpeg, png, or pdf.',
            'file.max'                      => 'ID front file must not exceed 5MB.',
            'file_back.required'            => 'The back of your government ID is required for the selected ID type.',
            'file_back.mimes'               => 'ID back file must be jpg, jpeg, png, or pdf.',
            'file_back.max'                 => 'ID back file must not exceed 5MB.',

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
            'cafe_phonenumber.regex'        => 'Café phone number must start with 09 and be 11 digits long (e.g., 09123456789).',
            'cafe_phonenumber.different'    => 'Café phone number and personal contact number must be different.',
            'cafe_phonenumber.unique'       => 'This café phone number is already registered or in use by another branch.',
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