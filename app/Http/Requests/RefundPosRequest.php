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
            'reason'       => ['required', 'string', 'max:255', 'not_regex:/^\s*$/'],
            'restock'      => ['nullable', 'boolean'], // true: never made, servings go back on sale
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required'  => 'Give a reason for the refund.',
            'reason.not_regex' => 'Give a reason for the refund.',
        ];
    }
}
