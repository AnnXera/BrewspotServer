<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One status change on a sale (refunded, voided) and who approved it.
 * Rows are written once and never updated.
 */
class TransactionEvent extends Model
{
    public const REFUNDED = 'refunded';
    public const VOIDED   = 'voided';

    public const UPDATED_AT = null;

    protected $table = 'transaction_events';
    protected $primaryKey = 'transaction_event_id';

    protected $fillable = [
        'transaction_id',
        'event',
        'user_id',
        'reason',
        'restocked',
    ];

    protected $casts = [
        'restocked' => 'boolean',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id', 'transaction_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
