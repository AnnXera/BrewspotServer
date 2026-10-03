<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RespondsWithJsonErrors;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH .../tables/{tableUuid}/status (dashboard) and
 * PATCH /api/pos/device/tables/{tableUuid}/status (register)
 */
class UpdateTableStatusRequest extends FormRequest
{
    use RespondsWithJsonErrors;

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(config('floor_plan.table_statuses'))],
        ];
    }
}
