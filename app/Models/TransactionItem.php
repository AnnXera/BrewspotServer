<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

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
        'unit_price',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
    ];

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
