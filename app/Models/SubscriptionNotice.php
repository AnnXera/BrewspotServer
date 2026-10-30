<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One owner notice (email + bell) sent for a subscription term. The unique
 * (sub_id, kind) pair is what stops the every-minute scheduler sending it twice.
 */
class SubscriptionNotice extends Model
{
    /** Days before end_date that an "ending soon" reminder goes out, most urgent first. */
    public const EXPIRING_DAYS = [1, 3, 7];

    public const KIND_EXPIRED = 'expired';

    protected $fillable = [
        'sub_id',
        'kind',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public static function expiringKind(int $days): string
    {
        return "expiring_{$days}d";
    }

    /** Every kind the reminder flow sends, i.e. the claims SendSubscriptionNotice may release. */
    public static function kinds(): array
    {
        return [
            ...array_map(self::expiringKind(...), self::EXPIRING_DAYS),
            self::KIND_EXPIRED,
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'sub_id', 'sub_id');
    }
}
