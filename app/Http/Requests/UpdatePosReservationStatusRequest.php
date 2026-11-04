<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RespondsWithFirstJsonError;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/pos/device/reservations/{reservationUuid}/status
 * The register can move a reservation along but not back to pending.
 */
class UpdatePosReservationStatusRequest extends FormRequest
{
    use RespondsWithFirstJsonError;

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:confirmed,seated,completed,cancelled,no_show'],
        ];
    }
}
