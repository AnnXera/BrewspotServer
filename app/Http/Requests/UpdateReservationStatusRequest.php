<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RespondsWithJsonErrors;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/{owner|manager}/branches/{branchUuid}/reservations/{reservationUuid}/status
 */
class UpdateReservationStatusRequest extends FormRequest
{
    use RespondsWithJsonErrors;

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(config('floor_plan.reservation_statuses'))],
        ];
    }
}
