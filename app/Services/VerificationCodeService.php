<?php

namespace App\Services;

use App\Mail\EmailVerificationMail;
use App\Repository\VerificationCodeRepository;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class VerificationCodeService
{
    public function __construct(
        private readonly VerificationCodeRepository $repo
    ) {}

    /**
     * Step 1a — Send a 6-digit verification code to the given email.
     */
    public function sendCode(string $email): array
    {
        $user = $this->repo->findOrCreatePendingUser($email);

        // Block already-active or fully registered users
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
            'code'  => $plainCode,
        ]);

        return [
            'success' => true,
            'message' => 'A 6-digit verification code has been sent to your email.',
        ];
    }

    /**
     * Step 1b — Verify the submitted 6-digit code.
     */
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
            'user_uuid' => $user->uuid,  // Frontend uses this UUID to proceed to Step 2
        ];
    }
}