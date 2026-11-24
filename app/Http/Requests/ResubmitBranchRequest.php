<?php

namespace App\Http\Requests;

use App\Models\CafeBranch;
use App\Rules\BranchTin;
use App\Rules\UniquePhoneNumber;
use App\Support\Tin;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * An owner correcting a rejected side branch and sending it back for review.
 * Same fields as adding a branch, but the BIR file and picture are optional
 * (the current ones are kept), and the branch's own email, phone and TIN
 * don't count as taken.
 */
class ResubmitBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('cafe_phonenumber'))) {
            $raw = trim($this->input('cafe_phonenumber'));
            if (! preg_match('/[a-zA-Z]/', $raw)) {
                $digits = preg_replace('/\D/', '', $raw);
                if (str_starts_with($digits, '09')) {
                    $digits = substr($digits, 1);
                } elseif (str_starts_with($digits, '639')) {
                    $digits = substr($digits, 2);
                }
                $this->merge(['cafe_phonenumber' => '+63' . $digits]);
            }
        }

        if (is_string($this->input('tin_number'))) {
            $this->merge(['tin_number' => Tin::normalize($this->input('tin_number'))]);
        }
    }

    public function rules(): array
    {
        $branchUuid = $this->route('uuid');
        $branch     = CafeBranch::where('uuid', $branchUuid)->first(['branch_id', 'cafe_id']);

        return [
            'branch_name'       => ['required', 'string', 'max:150'],
            'address'           => ['required', 'string'],
            'cafe_email'        => [
                'required', 'email', 'max:255',
                Rule::unique('cafe_branches', 'cafe_email')->whereNull('deleted_at')->ignore($branchUuid, 'uuid'),
            ],
            'cafe_phonenumber'  => [
                'required', 'string', 'max:20', 'regex:/^\+639\d{9}$/',
                new UniquePhoneNumber(ignoreBranchUuid: $branchUuid),
            ],
            'cafe_picture'      => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'bir_file'          => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'bir_registered_at' => ['required', 'date'],
            'bir_expired_at'    => ['nullable', 'date', 'after_or_equal:bir_registered_at'],
            'tin_number'        => [
                'required', 'string', 'regex:' . Tin::FORMAT_REGEX,
                BranchTin::forSideBranch($branch?->cafe_id, $branch?->branch_id),
            ],
            'vat'               => ['required', 'string', 'in:vat-registered,non-vat'],
        ];
    }

    public function messages(): array
    {
        return [
            'branch_name.required'       => 'Branch name is required.',
            'address.required'           => 'Branch address is required.',
            'cafe_email.required'        => 'Branch email is required.',
            'cafe_email.unique'          => 'This branch email is already in use.',
            'cafe_phonenumber.required'  => 'Branch phone number is required.',
            'cafe_phonenumber.regex'     => 'Branch phone number must be 10 digits (e.g., 9123456789).',
            'cafe_picture.mimes'         => 'Branch picture must be jpg, jpeg, png, or webp.',
            'cafe_picture.max'           => 'Branch picture must not exceed 2MB.',
            'bir_file.mimes'             => 'BIR file must be jpg, jpeg, png, or pdf.',
            'bir_file.max'               => 'BIR file must not exceed 5MB.',
            'bir_registered_at.required' => 'BIR registered date is required.',
            'tin_number.required'        => 'TIN number is required.',
            'tin_number.regex'           => Tin::FORMAT_MESSAGE,
            'vat.required'               => 'VAT type is required.',
            'vat.in'                     => 'VAT type must be either vat-registered or non-vat.',
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
