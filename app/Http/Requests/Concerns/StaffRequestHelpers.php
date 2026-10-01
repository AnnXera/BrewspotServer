<?php

namespace App\Http\Requests\Concerns;

use App\Models\CafeBranch;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Shared bits for the staff / PIN / schedule form requests.
 */
trait StaffRequestHelpers
{
    public const PIN_RULE = ['string', 'regex:/^\d{6}$/'];

    // PH mobile only, after normalizePhoneNumber() (e.g. +639123456789).
    public const PHONE_RULE = ['string', 'regex:/^\+639\d{9}$/'];

    public const PHONE_REGEX_MESSAGE = 'Phone number must be a valid PH mobile number (+639XXXXXXXXX).';

    /**
     * Normalises PH numbers to +63XXXXXXXXXX (same as CreateStaffRequest).
     */
    protected function normalizePhoneNumber(): void
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

    /**
     * No other user and no cafe branch may have this phone. Older rows store
     * numbers as 09…/639… rather than +639…, so every equivalent form is
     * checked. Runs after normalizePhoneNumber(); format is PHONE_RULE's job.
     */
    protected function uniquePhoneRule(?string $ignoreUserUuid = null): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($ignoreUserUuid) {
            if (! is_string($value) || ! preg_match('/^\+639\d{9}$/', $value)) {
                return;
            }

            $local    = substr($value, 3); // 9XXXXXXXXX
            $variants = ["+63{$local}", "63{$local}", "0{$local}", $local];

            $usedByUser = User::whereIn('phone_number', $variants)
                ->when($ignoreUserUuid, fn ($q) => $q->where('uuid', '!=', $ignoreUserUuid))
                ->exists();

            if ($usedByUser) {
                $fail('This phone number is already used by another account.');
                return;
            }

            if (CafeBranch::whereIn('cafe_phonenumber', $variants)->exists()) {
                $fail('This phone number is already used by a cafe branch.');
            }
        };
    }

    /**
     * No other user and no cafe branch may have this email (ignoring case).
     */
    protected function uniqueEmailRule(?string $ignoreUserUuid = null): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($ignoreUserUuid) {
            if (! is_string($value) || $value === '') {
                return;
            }

            $email = mb_strtolower(trim($value));

            $usedByUser = User::whereRaw('LOWER(email) = ?', [$email])
                ->when($ignoreUserUuid, fn ($q) => $q->where('uuid', '!=', $ignoreUserUuid))
                ->exists();

            if ($usedByUser) {
                $fail('This email is already used by another account.');
                return;
            }

            if (CafeBranch::whereRaw('LOWER(cafe_email) = ?', [$email])->exists()) {
                $fail('This email is already used by a cafe branch.');
            }
        };
    }

    protected function scheduleRules(bool $required): array
    {
        return [
            'schedule'               => [$required ? 'required' : 'nullable', 'array', 'max:7'],
            'schedule.*.day_of_week' => ['required', 'integer', 'between:0,6', 'distinct'],
            'schedule.*.is_day_off'  => ['required', 'boolean'],
            'schedule.*.start_time'  => ['nullable', 'required_if:schedule.*.is_day_off,false', 'date_format:H:i'],
            'schedule.*.end_time'    => ['nullable', 'required_if:schedule.*.is_day_off,false', 'date_format:H:i'],
        ];
    }

    /**
     * Shifts must end after they start (no overnight shifts).
     */
    protected function validateScheduleTimes(Validator $validator): void
    {
        foreach ((array) $this->input('schedule', []) as $i => $day) {
            if (! empty($day['is_day_off']) || empty($day['start_time']) || empty($day['end_time'])) {
                continue;
            }

            if (strcmp($day['end_time'], $day['start_time']) <= 0) {
                $validator->errors()->add("schedule.$i.end_time", 'End time must be after start time.');
            }
        }
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
