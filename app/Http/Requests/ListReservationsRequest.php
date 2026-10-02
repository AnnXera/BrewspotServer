<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RespondsWithJsonErrors;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET .../reservations  (and GET /api/pos/device/reservations)
 */
class ListReservationsRequest extends FormRequest
{
    use RespondsWithJsonErrors;

    public function rules(): array
    {
        return [
            'date'             => ['nullable', 'date_format:Y-m-d'],
            'from'             => ['nullable', 'date_format:Y-m-d'],
            'to'               => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status'           => ['nullable', Rule::in(config('floor_plan.reservation_statuses'))],
            'table_uuid'       => ['nullable', 'string', 'max:64'],
            'floor_plan_uuid'  => ['nullable', 'string', 'max:64'],
            'search'           => ['nullable', 'string', 'max:100'],
            'per_page'         => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
