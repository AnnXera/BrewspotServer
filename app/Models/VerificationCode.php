<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class VerificationCode extends Model
{
    protected $primaryKey = 'code_id';

    public $timestamps = false; // Table only has created_at, no updated_at

    protected $fillable = [
        'uuid',
        'user_id',
        'code_hash',
        'purpose',
        'is_used',
        'expires_at',
        'created_at',
    ];

    protected $casts = [
        'is_used'    => 'boolean',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($code) {
            if (empty($code->uuid)) {
                $code->uuid = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}