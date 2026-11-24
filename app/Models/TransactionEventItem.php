<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item returned by a refund: how many of the line, and the money given back for them.
 * Written once with its refund event and never updated.
 */
class TransactionEventItem extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'transaction_event_items';
    protected $primaryKey = 'transaction_event_item_id';

    protected $fillable = [
        'transaction_event_id',
        'transaction_item_id',
        'quantity',
        'amount',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'amount'   => 'decimal:2',
    ];

    /**
     * Per sold line: how many were refunded (`tracked`) and how many of those were never
     * made (`unmade`: the refund put the servings back on sale). Ingredient usage counts
     * a line's quantity less `unmade`: made-then-refunded items still used their ingredients.
     * Join on transaction_item_id; lines with no refund have no row.
     */
    public static function refundsPerLine(): \Illuminate\Database\Query\Builder
    {
        return \Illuminate\Support\Facades\DB::table('transaction_event_items as tei')
            ->join('transaction_events as te', 'te.transaction_event_id', '=', 'tei.transaction_event_id')
            ->groupBy('tei.transaction_item_id')
            ->selectRaw('tei.transaction_item_id,
                SUM(tei.quantity) as tracked,
                SUM(CASE WHEN te.restocked = ? THEN tei.quantity ELSE 0 END) as unmade', [true]);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(TransactionEvent::class, 'transaction_event_id', 'transaction_event_id');
    }

    public function transactionItem(): BelongsTo
    {
        return $this->belongsTo(TransactionItem::class, 'transaction_item_id', 'transaction_item_id');
    }
}
