<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Subscription extends Model
{
    protected $primaryKey = 'sub_id';

    /**
     * Trailing days of a term the owner has access to but did not pay for.
     *
     * SubscriptionRepository::activate() builds every term as paid days + this, and it is
     * also the window in which the next term can be bought — see renewalOpensAt().
     */
    public const GRACE_DAYS = 1;

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'uuid',
        'user_id',
        'sub_plan_id',
        'pending_sub_plan_id',
        'pending_billing_cycle',
        'start_date',
        'end_date',
        'status',
        'billing_cycle',
        'cancel_at_period_end',
        'expiration_reminder_sent_at',
        'renewal_open_reminder_sent_at',
        'expired_notice_sent_at',
        'gateway_subscription_id',
    ];

    protected $casts = [
        'start_date'                    => 'datetime',
        'end_date'                      => 'datetime',
        'cancel_at_period_end'          => 'boolean',
        'expiration_reminder_sent_at'   => 'datetime',
        'renewal_open_reminder_sent_at' => 'datetime',
        'expired_notice_sent_at'        => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($sub) => $sub->uuid = (string) Str::uuid());
    }

    /**
     * When the owner may pay for their next term: the moment their paid days run out,
     * leaving only the grace day. Null when the term has no end date.
     */
    public function renewalOpensAt(): ?Carbon
    {
        return $this->end_date?->copy()->subDays(self::GRACE_DAYS);
    }

    /**
     * Whether the next term can be paid for yet.
     *
     * A term with no end date has nothing to renew against, so it counts as open rather
     * than locking the owner out of paying at all.
     */
    public function isRenewalOpen(): bool
    {
        $opensAt = $this->renewalOpensAt();

        return $opensAt === null || Carbon::now()->gte($opensAt);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'sub_plan_id', 'sub_plan_id');
    }

    public function pendingPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'pending_sub_plan_id', 'sub_plan_id');
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function latestPayment(): MorphOne
    {
        return $this->morphOne(Payment::class, 'payable')->latestOfMany('payments_id');
    }
}