<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RespondsWithJsonErrors;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/{owner|manager}/branches/{branchUuid}/reservations/{reservationUuid}
 *
 * Only pending/confirmed reservations can be edited (checked in the service).
 */
class UpdateReservationRequest extends FormRequest
{
    use RespondsWithJsonErrors;

    public function rules(): array
    {
        return [
            'table_uuid'       => ['sometimes', 'string', 'max:64'],
            'customer_name'    => ['sometimes', 'string', 'max:100'],
            'customer_phone'   => ['sometimes', 'string', StoreReservationRequest::PHONE_RULE],
            'customer_email'   => ['sometimes', 'nullable', 'email', 'max:150'],
            'party_size'       => ['sometimes', 'integer', 'min:1', 'max:100'],
            'reservation_date' => ['sometimes', 'date'],
            'reservation_end'  => array_filter(['sometimes', 'date', $this->has('reservation_date') ? 'after:reservation_date' : null]),
            'notes'            => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_phone.regex'  => 'Enter a valid phone number.',
            'reservation_end.after' => 'The end time must be after the start time.',
        ];
    }
}
