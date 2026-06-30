<?php

namespace App\Repository;

use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Carbon;

class VerificationCodeRepository
{
    /**
     * Find an existing pending_application user by email,
     * or create a shell user so the verification code has
     * a user_id FK to point to.
     */
    public function findOrCreatePendingUser(string $email): User
    {
        return User::firstOrCreate(
            ['email' => $email],
            [
                'status' => 'email_unverified',
                'role_id' => 2, // Cafe Owner by default
            ]
        );
    }

    /**
     * Invalidate all previous unused codes for this user + purpose,
     * then create a fresh one.
     */
    public function createCode(int $userId, string $plainCode, string $purpose = 'email_verification'): VerificationCode
    {
        // Expire old codes for this user and purpose
        VerificationCode::where('user_id', $userId)
            ->where('purpose', $purpose)
            ->where('is_used', false)
            ->update(['is_used' => true]);

        return VerificationCode::create([
            'user_id'    => $userId,
            'code_hash'  => Hash::make($plainCode),
            'purpose'    => $purpose,
            'is_used'    => false,
            'expires_at' => Carbon::now()->addMinutes(15),
        ]);
    }

    /**
     * Find the latest unused, unexpired code for the given user + purpose.
     */
    public function findValidCode(int $userId, string $purpose = 'email_verification'): ?VerificationCode
    {
        return VerificationCode::where('user_id', $userId)
            ->where('purpose', $purpose)
            ->where('is_used', false)
            ->where('expires_at', '>', Carbon::now())
            ->latest('created_at')
            ->first();
    }

    /**
     * Mark a code as used and mark the user's email as verified.
     */
    public function markVerified(VerificationCode $code): void
    {
        $code->update(['is_used' => true]);

        $code->user->update([
            'email_verified_at' => Carbon::now(),
            'status'            => 'filling_application',
        ]);
    }

    public function findUserByEmail(string $email): ?User
    {
        return User::where('email', $email)->first();
    }

    public function createLoginCode(int $userId, string $plainCode): VerificationCode
    {
        return $this->createCode($userId, $plainCode, 'login_2fa');
    }

    public function findValidLoginCode(int $userId): ?VerificationCode
    {
        return $this->findValidCode($userId, 'login_2fa');
    }
}