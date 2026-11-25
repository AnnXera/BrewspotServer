<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RespondsWithJsonErrors;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/{owner|manager}/branches/{branchUuid}/reservations
 *
 * `reservation_end` is chosen per booking (the default duration only
 * pre-fills the form). Business rules (capacity, hours, overlap) are in
 * ReservationService.
 */
class StoreReservationRequest extends FormRequest
{
    use RespondsWithJsonErrors;

    // PH mobile number: 11 digits starting with 09, checked after normalizePhone() strips spaces and dashes.
    public const PHONE_RULE = 'regex:/^09\d{9}$/';

    public const PHONE_MESSAGE = 'Enter an 11-digit phone number starting with 09.';

    // "0917 123 4567" and "0917-123-4567" are stored as "09171234567".
    public static function normalizePhone(mixed $phone): mixed
    {
        return is_string($phone) ? preg_replace('/[\s\-().]/', '', $phone) : $phone;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('customer_phone')) {
            $this->merge(['customer_phone' => self::normalizePhone($this->input('customer_phone'))]);
        }
    }

    public function rules(): array
    {
        return [
            'table_uuid'       => ['required', 'string', 'max:64'],
            'customer_name'    => ['required', 'string', 'max:100'],
            'customer_phone'   => ['required', 'string', self::PHONE_RULE],
            'customer_email'   => ['nullable', 'email', 'max:150'],
            'party_size'       => ['required', 'integer', 'min:1', 'max:100'],
            'reservation_date' => ['required', 'date'],
            'reservation_end'  => ['required', 'date', 'after:reservation_date'],
            // New bookings are always saved as confirmed; the field is accepted for older clients.
            'status'           => ['nullable', Rule::in(['pending', 'confirmed'])],
            'notes'            => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'table_uuid.required'       => 'Please choose a table.',
            'customer_name.required'    => 'Customer name is required.',
            'customer_phone.required'   => 'Customer phone number is required.',
            'customer_phone.regex'      => self::PHONE_MESSAGE,
            'reservation_date.required' => 'Choose when the reservation starts.',
            'reservation_end.required'  => 'Choose when the reservation ends.',
            'reservation_end.after'     => 'The end time must be after the start time.',
        ];
    }
}
