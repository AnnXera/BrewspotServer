<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CafeDocument extends Model
{
    protected $primaryKey = 'cafe_doc_id';

    protected $fillable = [
        'cafe_id',
        'doc_type',
        'file',
        'registered_at',
        'expired_at',
    ];

    protected $casts = [
        'registered_at' => 'datetime',
        'expired_at'    => 'datetime',
    ];

    public function cafe(): BelongsTo
    {
        return $this->belongsTo(Cafe::class, 'cafe_id', 'cafe_id');
    }
}