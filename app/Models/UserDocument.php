<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class UserDocument extends Model
{
    protected $primaryKey = 'user_doc_id';

    public static array $allowedIds = [
        'national_id'     => 'National ID',
        'passport'        => 'Passport',
        'drivers_license' => 'Driver\'s License',
        'sss'             => 'SSS ID',
        'philhealth'      => 'PhilHealth ID',
        'pagibig'         => 'Pag-IBIG ID',
        'voters_id'       => 'Voter\'s ID',
    ];

    protected $fillable = [
        'uuid',
        'user_id',
        'file',
        'id_type',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($doc) => $doc->uuid = (string) Str::uuid());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}