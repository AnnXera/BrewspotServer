<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $transaction_item_id
 * @property string $uuid
 * @property int $transaction_id
 * @property int $men_item_id
 * @property int|null $men_size_id
 * @property string|null $size_name
 * @property string|null $temperature
 * @property int $quantity
 * @property float $unit_price
 */
class TransactionItem extends Model
{
    public const TEMPERATURES = ['hot', 'iced'];

    protected $primaryKey = 'transaction_item_id';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'uuid',
        'transaction_id',
        'men_item_id',
        'men_size_id',
        'size_name', // snapshot at time of sale
        'temperature', // hot or iced, on items sold both ways
        'quantity',
        'refunded_quantity', // running count refunded from this line
        'unit_price',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'refunded_quantity' => 'integer',
        'unit_price' => 'decimal:2',
    ];

    /** How many of the line can still be refunded. */
    public function refundableQuantity(): int
    {
        return max(0, (int) $this->quantity - (int) $this->refunded_quantity);
    }

    protected static function booted(): void
    {
        static::creating(fn ($item) => $item->uuid = (string) Str::uuid());
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id', 'transaction_id');
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'men_item_id', 'men_item_id');
    }

    public function addons(): HasMany
    {
        return $this->hasMany(TransactionItemAddon::class, 'transaction_item_id', 'transaction_item_id');
    }

    public function consumptionLogs(): HasMany
    {
        return $this->hasMany(IngredientConsumptionLog::class, 'transaction_item_id', 'transaction_item_id');
    }
}
