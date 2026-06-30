<?php

namespace App\Services;

use App\Mail\LoginCodeMail;
use App\Models\User;
use App\Repository\AuthRepository;
use App\Repository\VerificationCodeRepository;
use App\Http\Resources\UserResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AuthService
{
    public function __construct(
        private readonly AuthRepository $repo,
        private readonly VerificationCodeRepository $codeRepo
    ) {}

    /**
     * Step 1 — Validate credentials and send 2FA code.
     */
    public function login(string $email, string $password): array
    {
        $user = $this->repo->findByEmail($email);

        if (! $user) {
            Log::channel('auth')->warning('Login failed — email not found.', [
                'email' => $email,
            ]);

            return [
                'success' => false,
                'message' => 'No account found with this email.',
            ];
        }

        if (! Hash::check($password, $user->password_hash)) {
            Log::channel('auth')->warning('Login failed — wrong password.', [
                'email'   => $email,
                'user_id' => $user->user_id,
            ]);

            return [
                'success' => false,
                'message' => 'Invalid credentials.',
            ];
        }

        if (! $user->email_verified_at) {
            Log::channel('auth')->warning('Login failed — email not verified.', [
                'email'   => $email,
                'user_id' => $user->user_id,
            ]);

            return [
                'success' => false,
                'message' => 'Please verify your email before logging in.',
            ];
        }

        if ($user->status !== 'active') {
            Log::channel('auth')->warning('Login failed — account not active.', [
                'email'   => $email,
                'status'  => $user->status,
                'user_id' => $user->user_id,
            ]);

            return [
                'success' => false,
                'message' => $this->statusMessage($user->status),
            ];
        }

        // Generate and send 2FA code
        $plainCode = (string) random_int(100000, 999999);
        $this->codeRepo->createLoginCode($user->user_id, $plainCode);

        Mail::to($user->email)->send(new LoginCodeMail($plainCode, $user->email));

        Log::channel('auth')->info('Credentials valid — 2FA code sent.', [
            'email'   => $email,
            'user_id' => $user->user_id,
        ]);

        return [
            'success'      => true,
            'message'      => 'A verification code has been sent to your email. Please enter it to complete login.',
            'requires_2fa' => true,
            'user_uuid'    => $user->uuid,
        ];
    }

    /**
     * Resend the login 2FA code, enforcing a 60s cooldown.
     */
    public function resendLoginCode(string $email): array
    {
        $user = $this->repo->findByEmail($email);

        if (! $user) {
            Log::channel('auth')->warning('Resend 2FA blocked — email not found.', ['email' => $email]);

            return ['success' => false, 'message' => 'No account found with this email.'];
        }

        $cooldownCheck = $this->checkCooldown($user->user_id, 'login_2fa');

        if (! $cooldownCheck['allowed']) {
            return $cooldownCheck['response'];
        }

        $plainCode = (string) random_int(100000, 999999);
        $this->codeRepo->createLoginCode($user->user_id, $plainCode);

        Mail::to($user->email)->send(new LoginCodeMail($plainCode, $user->email));

        Log::channel('auth')->info('Login 2FA code resent.', [
            'email'   => $email,
            'user_id' => $user->user_id,
        ]);

        return [
            'success' => true,
            'message' => 'A new verification code has been sent to your email.',
        ];
    }

    /**
     * Shared cooldown checker.
     * Returns ['allowed' => bool, 'response' => array|null]
     */
    private function checkCooldown(int $userId, string $purpose): array
    {
        $latestCode = $this->codeRepo->findLatestCode($userId, $purpose);

        if (! $latestCode) {
            return ['allowed' => true];
        }

        // Positive = seconds elapsed since the code was sent
        $secondsSinceSent = $latestCode->created_at->diffInSeconds(Carbon::now(), false);

        // Clamp negative values (clock skew / just-created codes) to 0
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

    /**
     * Step 2 — Verify the 2FA code and issue the auth token.
     */
    public function verifyLoginCode(string $email, string $plainCode): array
    {
        $user = $this->repo->findByEmail($email);

        if (! $user) {
            Log::channel('auth')->warning('2FA verify failed — email not found.', ['email' => $email]);

            return ['success' => false, 'message' => 'No account found with this email.'];
        }

        $code = $this->codeRepo->findValidLoginCode($user->user_id);

        if (! $code) {
            Log::channel('auth')->warning('2FA verify failed — no valid code.', [
                'email'   => $email,
                'user_id' => $user->user_id,
            ]);

            return ['success' => false, 'message' => 'Code is invalid, expired, or already used.'];
        }

        if (! Hash::check($plainCode, $code->code_hash)) {
            Log::channel('auth')->warning('2FA verify failed — wrong code.', [
                'email'   => $email,
                'user_id' => $user->user_id,
            ]);

            return ['success' => false, 'message' => 'The code you entered is incorrect.'];
        }

        $code->update(['is_used' => true]);

        $token = $this->repo->createToken($user);

        Log::channel('auth')->info('Login successful — 2FA verified.', [
            'email'   => $email,
            'user_id' => $user->user_id,
            'role'    => $user->role->role_name,
        ]);

        return [
            'success'    => true,
            'message'    => 'Login successful.',
            'token'      => $token,
            'token_type' => 'Bearer',
            'role'       => $user->role->role_name,
            'redirect'   => $this->redirectPath($user->role->role_name),
            'user'       => new UserResource($user),
        ];
    }

    public function logout(User $user): array
    {
        $user->tokens()->delete();

        Log::channel('auth')->info('Logout successful.', [
            'user_id' => $user->user_id,
            'email'   => $user->email,
        ]);

        return [
            'success' => true,
            'message' => 'Logged out successfully.',
        ];
    }

    private function redirectPath(string $roleName): string
    {
        return match($roleName) {
            'Admin'      => '/admin/dashboard',
            'Cafe Owner' => '/owner/dashboard',
            'Manager'    => '/manager/dashboard',
            'Cashier'    => '/cashier/dashboard',
            default      => '/dashboard',
        };
    }

    private function statusMessage(string $status): string
    {
        return match($status) {
            'email_unverified'    => 'Please verify your email before logging in.',
            'filling_application' => 'Please complete your registration first.',
            'pending_approval'    => 'Your account is still pending admin approval.',
            'approved'            => 'Please check your email to set up your password before logging in.',
            'rejected'            => 'Your account application has been rejected.',
            'inactive'            => 'Your account has been deactivated.',
            default               => 'Your account is not eligible to log in.',
        };
    }
}