<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class IngredientConsumptionLog extends Model
{
    protected $primaryKey = 'consumption_id';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'uuid',
        'transaction_item_id',
        'ingredient_name',
        'quantity_consumed',
        'unit',
    ];

    protected $casts = [
        'quantity_consumed' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($log) => $log->uuid = (string) Str::uuid());
    }

    public function transactionItem(): BelongsTo
    {
        return $this->belongsTo(TransactionItem::class, 'transaction_item_id', 'transaction_item_id');
    }
}
