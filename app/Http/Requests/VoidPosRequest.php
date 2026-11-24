<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RespondsWithFirstJsonError;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/pos/device/transactions/{transactionUuid}/void
 */
class VoidPosRequest extends FormRequest
{
    use RespondsWithFirstJsonError;

    public function rules(): array
    {
        return [
            'manager_uuid' => ['required', 'string'],
            'pin'          => ['required', 'string'],
            'reason'       => ['required', 'string', 'max:255', 'not_regex:/^\s*$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required'  => 'Give a reason for the void.',
            'reason.not_regex' => 'Give a reason for the void.',
        ];
    }
}
