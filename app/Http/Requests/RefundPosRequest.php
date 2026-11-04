<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RespondsWithFirstJsonError;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/pos/device/transactions/{transactionUuid}/refund
 */
class RefundPosRequest extends FormRequest
{
    use RespondsWithFirstJsonError;

    public function rules(): array
    {
        return [
            'manager_uuid' => ['required', 'string'],
            'pin'          => ['required', 'string'],
            'reason'       => ['nullable', 'string', 'max:255'],
            'restock'      => ['nullable', 'boolean'], // true: never made, servings go back on sale
        ];
    }
}
