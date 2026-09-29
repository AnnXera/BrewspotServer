<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * One PIN per person, shared across every branch they work at.
 *
 * A PIN is only ever checked on a registered POS device (see
 * PosDeviceService), never on an open endpoint. After MAX_ATTEMPTS wrong
 * tries the PIN is locked until an owner/manager sets a new one.
 *
 * A manager's PIN approves voids/refunds, so when someone else (the owner)
 * sets it, it's temporary: pin_must_change stays true until the manager
 * picks their own. Cashier PINs are never temporary.
 */
class StaffPinService
{
    public const MAX_ATTEMPTS = 5;

    public function setPin(User $user, string $plainPin, bool $temporary = false): void
    {
        $user->forceFill([
            'pin_hash'            => Hash::make($plainPin),
            'pin_failed_attempts' => 0,
            'pin_locked_at'       => null,
            'pin_must_change'     => $temporary,
        ])->save();
    }

    /**
     * Someone setting (or resetting) another person's PIN.
     */
    public function setPinFor(User $target, string $plainPin, User $setBy): void
    {
        $this->setPin($target, $plainPin, $this->isTemporaryWhenSetBy($target, $setBy));
    }

    public function isTemporaryWhenSetBy(User $target, User $setBy): bool
    {
        return $target->isManager() && $setBy->user_id !== $target->user_id;
    }

    /**
     * Replacing a temporary PIN on the register: the person proves they
     * know the current PIN, then picks a new one only they know.
     */
    public function changeWithCurrentPin(User $user, string $currentPin, string $newPin): array
    {
        $check = $this->verify($user, $currentPin);

        if (! $check['success']) {
            return $check;
        }

        if ($currentPin === $newPin) {
            return ['success' => false, 'message' => 'Choose a PIN different from the temporary one.'];
        }

        $this->setPin($user, $newPin);

        Log::channel('auth')->info('Staff replaced PIN on register.', ['user_uuid' => $user->uuid]);

        return ['success' => true];
    }

    /**
     * Checks the PIN only. Callers that act on a successful check (unlock,
     * approvals) must also refuse while $user->pin_must_change is true.
     *
     * @return array{success: bool, message?: string, locked?: bool, attempts_left?: int}
     */
    public function verify(User $user, string $plainPin): array
    {
        if (! $user->hasPin()) {
            return ['success' => false, 'message' => 'No PIN has been set for this account. Ask your manager to set one.'];
        }

        if ($user->isPinLocked()) {
            return ['success' => false, 'locked' => true, 'message' => 'This PIN is locked. Ask your manager or the owner to reset it.'];
        }

        if (Hash::check($plainPin, $user->pin_hash)) {
            if ($user->pin_failed_attempts > 0) {
                $user->forceFill(['pin_failed_attempts' => 0])->save();
            }

            return ['success' => true];
        }

        $attempts = $user->pin_failed_attempts + 1;
        $locked   = $attempts >= self::MAX_ATTEMPTS;

        $user->forceFill([
            'pin_failed_attempts' => $attempts,
            'pin_locked_at'       => $locked ? Carbon::now() : null,
        ])->save();

        Log::channel('auth')->warning($locked ? 'Staff PIN locked after repeated failures.' : 'Staff PIN rejected.', [
            'user_uuid' => $user->uuid,
            'attempts'  => $attempts,
        ]);

        if ($locked) {
            return ['success' => false, 'locked' => true, 'message' => 'Too many wrong attempts. This PIN is now locked. Ask your manager or the owner to reset it.'];
        }

        return [
            'success'       => false,
            'attempts_left' => self::MAX_ATTEMPTS - $attempts,
            'message'       => 'Incorrect PIN.',
        ];
    }

    /**
     * A manager changing their own PIN. Requires their dashboard password so
     * a borrowed, logged-in laptop isn't enough to take over their approvals.
     */
    public function changeOwnPin(User $user, string $currentPassword, string $newPin): array
    {
        if (! $user->password_hash || ! Hash::check($currentPassword, $user->password_hash)) {
            return ['success' => false, 'message' => 'Your current password is incorrect.'];
        }

        $this->setPin($user, $newPin);

        Log::channel('auth')->info('Manager changed own PIN.', ['user_uuid' => $user->uuid]);

        return ['success' => true, 'message' => 'Your PIN has been updated.'];
    }
}
