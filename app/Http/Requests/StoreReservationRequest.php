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

    public const PHONE_RULE = 'regex:/^[0-9+\-\s()]{7,20}$/';

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
            'customer_phone.regex'      => 'Enter a valid phone number.',
            'reservation_date.required' => 'Choose when the reservation starts.',
            'reservation_end.required'  => 'Choose when the reservation ends.',
            'reservation_end.after'     => 'The end time must be after the start time.',
        ];
    }
}
