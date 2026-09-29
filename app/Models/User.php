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
        'address',
        'email_verified_at',
        'role_id',
        'status',
        'gateway_customer_id',
    ];

    // pin_hash / pin_failed_attempts / pin_locked_at are deliberately not
    // fillable — they're only written through StaffPinService via forceFill.
    protected $hidden = [
        'password_hash',
        'pin_hash',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'pin_locked_at'      => 'datetime',
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

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'user_id', 'user_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(UserDocument::class, 'user_id', 'user_id');
    }

    public function verificationCodes(): HasMany
    {
        return $this->hasMany(VerificationCode::class, 'user_id', 'user_id');
    }

    public function staffAssignments(): HasMany
    {
        return $this->hasMany(CafeStaff::class, 'user_id', 'user_id');
    }

    public function activeStaffAssignments(): HasMany
    {
        return $this->staffAssignments()->where('employment_status', CafeStaff::STATUS_ACTIVE);
    }

    public function roleName(): ?string
    {
        return $this->role?->role_name;
    }

    public function isOwner(): bool
    {
        return $this->roleName() === 'Cafe Owner';
    }

    public function isManager(): bool
    {
        return $this->roleName() === 'Manager';
    }

    public function isCashier(): bool
    {
        return $this->roleName() === 'Cashier';
    }

    public function hasPin(): bool
    {
        return $this->pin_hash !== null;
    }

    public function isPinLocked(): bool
    {
        return $this->pin_locked_at !== null;
    }

    /**
     * The account whose subscription decides feature access for this user.
     * Owners use their own; staff use the owner of the cafe they work at.
     */
    public function featureOwner(): ?User
    {
        if (! $this->isManager() && ! $this->isCashier()) {
            return $this;
        }

        $assignment = $this->activeStaffAssignments()->with('branch.cafe.owner')->first();

        return $assignment?->branch?->cafe?->owner;
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'created_by', 'user_id');
    }

    public function canAccessFeature(string $featureKey): bool
    {
        // Admin has full access to all features
        if ($this->role && strtolower($this->role->role_name) === 'admin') {
            return true;
        }

        $owner = $this->featureOwner();

        if (! $owner) {
            return false;
        }

        // Check active subscription plan
        $activeSubscription = $owner->subscriptions()
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('end_date')
                      ->orWhere('end_date', '>=', now());
            })
            ->with('plan.features')
            ->latest('start_date')
            ->first();

        if (! $activeSubscription || ! $activeSubscription->plan) {
            return false;
        }

        $features = $activeSubscription->plan->features;

        if ($features instanceof \Illuminate\Support\Collection) {
            return $features->contains('key', $featureKey);
        }

        if (is_array($features)) {
            // Check if featureKey is in list or key exists and is truthy
            if (in_array($featureKey, $features, true)) {
                return true;
            }
            if (isset($features[$featureKey]) && $features[$featureKey]) {
                return true;
            }
        }

        return false;
    }
}