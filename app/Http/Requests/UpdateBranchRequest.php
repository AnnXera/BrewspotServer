<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

use App\Models\CafeBranch;
use App\Rules\BranchTin;
use App\Rules\UniquePhoneNumber;
use App\Support\Tin;
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

    private function tinRule(): BranchTin
    {
        $branch = CafeBranch::where('uuid', $this->route('uuid'))->first(['branch_id', 'cafe_id', 'branch_type']);

        return $branch?->branch_type === 'main'
            ? BranchTin::forMainBranch()
            : BranchTin::forSideBranch($branch?->cafe_id, $branch?->branch_id);
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
                'regex:/^\+639\d{9}$/',
                new UniquePhoneNumber(ignoreBranchUuid: $branchUuid),
            ],
            'address'              => ['nullable', 'string'],
            'status'               => ['nullable', 'string', 'in:active,inactive'],
            'bir_file'             => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'bir_registered_at'    => ['nullable', 'date'],
            'tin_number'           => ['nullable', 'string', 'regex:' . Tin::FORMAT_REGEX, $this->tinRule()],
            'vat'                  => ['nullable', 'string', 'in:vat-registered,non-vat'],
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
            'cafe_phonenumber.regex'        => 'Branch phone number must be 10 digits (e.g., 9123456789).',
            'status.in'                     => 'Status must be either active or inactive.',
            'bir_file.mimes'                => 'BIR file must be jpg, jpeg, png, or pdf.',
            'bir_file.max'                  => 'BIR file must not exceed 5MB.',
            'tin_number.regex'              => Tin::FORMAT_MESSAGE,
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
