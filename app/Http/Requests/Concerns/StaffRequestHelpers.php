<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Shared bits for the staff / PIN / schedule form requests.
 */
trait StaffRequestHelpers
{
    public const PIN_RULE = ['string', 'regex:/^\d{4}$/'];

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
