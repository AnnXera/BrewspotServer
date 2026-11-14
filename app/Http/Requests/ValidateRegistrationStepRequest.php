<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RespondsWithJsonErrors;
use App\Models\User;
use App\Repository\RegistrationRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/auth/validate-registration-step/{uuid?}
 *
 * Checks only the fields that were sent, so the wizard can validate one step at a time.
 */
class ValidateRegistrationStepRequest extends FormRequest
{
    use RespondsWithJsonErrors;

    private const PHONE_REGEX = 'regex:/^(\+639\d{9}|082\d{7})$/';

    protected function prepareForValidation(): void
    {
        foreach (['phone_number', 'cafe_phonenumber'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => $this->normalizePhone($this->input($field))]);
            }
        }
    }

    public function rules(): array
    {
        $user = $this->route('uuid')
            ? User::where('uuid', $this->route('uuid'))->first()
            : null;
        $userId = $user?->user_id;

        // A rejected application being resubmitted keeps its branch until submit,
        // so its own email and phone are not "taken".
        $oldBranchId = $user
            ? app(RegistrationRepository::class)->findResubmittableApplication($user)?->branch_id
            : null;

        $rules = [];

        if ($this->sent('username')) {
            $rules['username'] = [
                'required', 'string', 'max:50',
                Rule::unique('users', 'username')->ignore($userId, 'user_id'),
            ];
        }

        if ($this->sent('phone_number')) {
            $rules['phone_number'] = [
                'required', 'string', 'max:20', self::PHONE_REGEX,
                Rule::unique('users', 'phone_number')->ignore($userId, 'user_id'),
                Rule::unique('cafe_branches', 'cafe_phonenumber')->whereNull('deleted_at')->ignore($oldBranchId, 'branch_id'),
            ];
        }

        if ($this->sent('cafe_email')) {
            $rules['cafe_email'] = [
                'required', 'email', 'max:255',
                Rule::unique('cafe_branches', 'cafe_email')->whereNull('deleted_at')->ignore($oldBranchId, 'branch_id'),
            ];
        }

        if ($this->sent('cafe_phonenumber')) {
            $rules['cafe_phonenumber'] = [
                'required', 'string', 'max:20', self::PHONE_REGEX,
                Rule::unique('cafe_branches', 'cafe_phonenumber')->whereNull('deleted_at')->ignore($oldBranchId, 'branch_id'),
                Rule::unique('users', 'phone_number')->ignore($userId, 'user_id'),
                ...($this->sent('phone_number') ? ['different:phone_number'] : []),
            ];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'username.required'          => 'Username is required.',
            'username.unique'            => 'This username is already taken.',
            'phone_number.required'      => 'Personal phone number is required.',
            'phone_number.regex'         => 'Personal phone number must be a valid mobile number (+639xxxxxxxxx) or landline (082xxxxxxx).',
            'phone_number.unique'        => 'This personal phone number is already registered or in use by a café.',
            'cafe_email.required'        => 'Café email is required.',
            'cafe_email.email'           => 'Please enter a valid café email address.',
            'cafe_email.unique'          => 'This café email is already in use.',
            'cafe_phonenumber.required'  => 'Café phone number is required.',
            'cafe_phonenumber.regex'     => 'Café phone number must be a valid mobile number (+639xxxxxxxxx) or landline (082xxxxxxx).',
            'cafe_phonenumber.unique'    => 'This café phone number is already registered or in use by another branch.',
            'cafe_phonenumber.different' => 'Café phone number and personal contact number must be different.',
        ];
    }

    /** True when the field is present and not null (the old isset() check). */
    private function sent(string $field): bool
    {
        return $this->input($field) !== null;
    }

    /**
     * 082 landlines stay as digits; mobiles become +639XXXXXXXXX.
     * Anything containing letters is left alone for the regex to reject.
     */
    private function normalizePhone(string $value): string
    {
        $raw = trim($value);

        if (preg_match('/[a-zA-Z]/', $raw)) {
            return $value;
        }

        $digits = preg_replace('/\D/', '', $raw);

        if (str_starts_with($digits, '082') && strlen($digits) === 10) {
            return $digits;
        }

        if (str_starts_with($digits, '09')) {
            $digits = substr($digits, 1);
        } elseif (str_starts_with($digits, '639')) {
            $digits = substr($digits, 2);
        }

        return '+63' . $digits;
    }
}
