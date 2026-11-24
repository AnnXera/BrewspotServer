<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * @property int $transaction_id
 * @property string $uuid
 * @property string|null $receipt_number
 * @property int $branch_id
 * @property int $staff_id
 * @property float $total_amount
 * @property string|null $discount_type
 * @property float|null $discount_amount
 * @property string|null $discount_id_number
 * @property string|null $discount_id_image
 * @property string|null $walk_in_note
 * @property string $status
 * @property string|null $order_type
 */
class Transaction extends Model
{
    protected $primaryKey = 'transaction_id';

    public const ORDER_TYPES = ['dine_in', 'take_out', 'delivery'];

    /** Some items refunded; the rest is still a sale and counts as income (total_amount - refunded_amount). */
    public const PARTIALLY_REFUNDED = 'partially_refunded';

    /** Statuses that count as income. Reports subtract refunded_amount from total_amount. */
    public const SETTLED = ['completed', 'paid', self::PARTIALLY_REFUNDED];

    /** Money this sale still brings in after refunds. */
    public function netAmount(): float
    {
        return round((float) $this->total_amount - (float) $this->refunded_amount, 2);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'uuid',
        'receipt_number',
        'branch_id',
        'staff_id',
        'total_amount',
        'refunded_amount', // running money refunded, item by item
        'discount_type',
        'discount_amount',
        'discount_id_number',
        'discount_id_image',
        'walk_in_note',
        'status',
        'order_type',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'refunded_amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($transaction) => $transaction->uuid = (string) Str::uuid());
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(CafeBranch::class, 'branch_id', 'branch_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(CafeStaff::class, 'staff_id', 'staff_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransactionItem::class, 'transaction_id', 'transaction_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TransactionEvent::class, 'transaction_id', 'transaction_id');
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }
}
