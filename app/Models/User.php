<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasFactory, HasApiTokens;

    protected $primaryKey = 'user_id';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'uuid',
        'firstname',
        'middlename',
        'lastname',
        'username',
        'password_hash',
        'email',
        'phone_number',
        'email_verified_at',
        'role_id',
        'status',
    ];

    protected $hidden = [
        'password_hash',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'created_at'         => 'datetime',
        'updated_at'         => 'datetime',
    ];

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    protected static function booted(): void
    {
        static::creating(fn ($user) => $user->uuid = (string) Str::uuid());
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id', 'role_id');
    }

    public function cafes(): HasMany
    {
        return $this->hasMany(Cafe::class, 'user_id', 'user_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(ApprovalList::class, 'user_id', 'user_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'user_id', 'user_id');
    }
}