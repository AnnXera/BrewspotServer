<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RespondsWithFirstJsonError;
use App\Models\ServingSpoilageLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/pos/device/spoilage
 */
class LogSpoilageRequest extends FormRequest
{
    use RespondsWithFirstJsonError;

    public function rules(): array
    {
        return [
            'menu_item_uuid' => ['required', 'string'],
            'size_uuid'      => ['nullable', 'string'], // required for items that come in sizes
            'quantity'       => ['required', 'integer', 'min:1', 'max:1000'],
            'reason'         => ['nullable', 'string', Rule::in(ServingSpoilageLog::REASONS)],
        ];
    }
}
