<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RespondsWithFirstJsonError;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/pos/device/checkout
 */
class CheckoutPosRequest extends FormRequest
{
    use RespondsWithFirstJsonError;

    public function rules(): array
    {
        return [
            'items'                     => ['required', 'array', 'min:1'],
            'items.*.uuid'              => ['required', 'string'],
            'items.*.quantity'          => ['required', 'integer', 'min:1'],
            'items.*.size_uuid'         => ['nullable', 'string'],
            'items.*.temperature'       => ['nullable', Rule::in(TransactionItem::TEMPERATURES)],
            'items.*.addons'            => ['nullable', 'array'],
            'items.*.addons.*.uuid'     => ['required', 'string'],
            'items.*.addons.*.quantity' => ['required', 'integer', 'min:1'],
            'payment_method'            => ['required', 'string'],
            'amount_tendered'           => ['required', 'numeric', 'min:0'],
            'reference_number'          => ['nullable', 'string'],
            'discount_type'             => ['nullable', 'string', 'in:none,PWD,Senior'],
            'discount_amount'           => ['nullable', 'numeric', 'min:0'],
            'order_type'                => ['nullable', 'string', Rule::in(Transaction::ORDER_TYPES)],
            'note'                      => ['nullable', 'string', 'max:500'],
            'walk_in_note'              => ['nullable', 'string', 'max:500'],
        ];
    }
}
