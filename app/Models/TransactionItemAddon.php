<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An add-on on a sold line. Name and price are copied at the time of sale.
 * quantity is per drink: 2 Oreo on a line of 3 drinks = 6 servings of Oreo.
 */
class TransactionItemAddon extends Model
{
    protected $table = 'transaction_item_addons';
    protected $primaryKey = 'transaction_item_addon_id';

    protected $fillable = [
        'transaction_item_id',
        'addon_id',
        'addon_name',
        'price',
        'quantity',
    ];

    protected $casts = [
        'price'    => 'decimal:2',
        'quantity' => 'integer',
    ];

    public function transactionItem(): BelongsTo
    {
        return $this->belongsTo(TransactionItem::class, 'transaction_item_id', 'transaction_item_id');
    }

    public function addon(): BelongsTo
    {
        return $this->belongsTo(MenuAddon::class, 'addon_id', 'addon_id');
    }
}
