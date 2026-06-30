<?php

namespace App\Services;

use App\Mail\EmailVerificationMail;
use App\Repository\VerificationCodeRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class VerificationCodeService
{
    public function __construct(
        private readonly VerificationCodeRepository $repo
    ) {}

    public function sendCode(string $email): array
    {
        $user = $this->repo->findOrCreatePendingUser($email);

        if (! in_array($user->status, ['email_unverified'])) {
            Log::channel('verification')->warning('Send code blocked — user not in email_unverified status.', [
                'email'  => $email,
                'status' => $user->status,
            ]);

            return [
                'success' => false,
                'message' => 'This email is already associated with an active or processed account.',
            ];
        }

        $plainCode = (string) random_int(100000, 999999);

        $this->repo->createCode($user->user_id, $plainCode);

        Mail::to($email)->send(new EmailVerificationMail($plainCode, $email));

        Log::channel('verification')->info('Verification code sent successfully.', [
            'email'   => $email,
            'user_id' => $user->user_id,
        ]);

        return [
            'success' => true,
            'message' => 'A 6-digit verification code has been sent to your email.',
        ];
    }

    /**
     * Resend the email verification code, enforcing a 60s cooldown.
     */
    public function resendVerificationCode(string $email): array
    {
        $user = $this->repo->findUserByEmail($email);

        if (! $user) {
            Log::channel('verification')->warning('Resend blocked — email not found.', ['email' => $email]);

            return ['success' => false, 'message' => 'No account found with this email.'];
        }

        if ($user->status !== 'email_unverified') {
            Log::channel('verification')->warning('Resend blocked — user not in email_unverified status.', [
                'email'  => $email,
                'status' => $user->status,
            ]);

            return ['success' => false, 'message' => 'This account is not eligible for a new verification code.'];
        }

        $cooldownCheck = $this->checkCooldown($user->user_id, 'email_verification');

        if (! $cooldownCheck['allowed']) {
            return $cooldownCheck['response'];
        }

        $plainCode = (string) random_int(100000, 999999);
        $this->repo->createCode($user->user_id, $plainCode);

        Mail::to($email)->send(new EmailVerificationMail($plainCode, $email));

        Log::channel('verification')->info('Verification code resent successfully.', [
            'email'   => $email,
            'user_id' => $user->user_id,
        ]);

        return [
            'success' => true,
            'message' => 'A new verification code has been sent to your email.',
        ];
    }

    public function verifyCode(string $email, string $plainCode): array
    {
        $user = $this->repo->findUserByEmail($email);

        if (! $user) {
            Log::channel('verification')->warning('Verify code failed — email not found.', ['email' => $email]);

            return ['success' => false, 'message' => 'No account found for this email.'];
        }

        $code = $this->repo->findValidCode($user->user_id);

        if (! $code) {
            Log::channel('verification')->warning('Verify code failed — no valid code found.', [
                'email'   => $email,
                'user_id' => $user->user_id,
            ]);

            return ['success' => false, 'message' => 'Code is invalid, expired, or already used.'];
        }

        if (! Hash::check($plainCode, $code->code_hash)) {
            Log::channel('verification')->warning('Verify code failed — wrong code submitted.', [
                'email'   => $email,
                'user_id' => $user->user_id,
            ]);

            return ['success' => false, 'message' => 'The code you entered is incorrect.'];
        }

        $this->repo->markVerified($code);

        Log::channel('verification')->info('Email verified successfully.', [
            'email'   => $email,
            'user_id' => $user->user_id,
        ]);

        return [
            'success'   => true,
            'message'   => 'Email verified successfully. You may now complete your registration.',
            'user_uuid' => $user->uuid,
        ];
    }

    /**
     * Shared cooldown checker.
     * Returns ['allowed' => bool, 'response' => array|null]
     */
    private function checkCooldown(int $userId, string $purpose): array
    {
        $latestCode = $this->repo->findLatestCode($userId, $purpose);

        if (! $latestCode) {
            return ['allowed' => true];
        }

        $secondsSinceSent = $latestCode->created_at->diffInSeconds(Carbon::now(), false);

        if ($secondsSinceSent < 0) {
            $secondsSinceSent = 0;
        }

        if ($secondsSinceSent < 60) {
            $remaining = (int) ceil(60 - $secondsSinceSent);

            return [
                'allowed'  => false,
                'response' => [
                    'success'             => false,
                    'message'             => "Please wait {$remaining} second(s) before requesting a new code.",
                    'retry_after_seconds' => $remaining,
                ],
            ];
        }

        return ['allowed' => true];
    }
}