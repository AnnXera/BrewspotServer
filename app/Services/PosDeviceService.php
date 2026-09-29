<?php

namespace App\Services;

use App\Http\Resources\PosDeviceResource;
use App\Models\CafeBranch;
use App\Models\CafeStaff;
use App\Models\PosDevice;
use App\Models\User;
use App\Repository\PosDeviceRepository;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Register (POS) lifecycle:
 *
 *   1. Setup   — an owner/manager signs in on the device once and registers
 *                it to a branch. The device gets its own token; the person's
 *                setup login is discarded.
 *   2. Unlock  — each shift, staff tap their name and enter their PIN. Only
 *                works with a device token, so PINs are never exposed to the
 *                open internet.
 *   3. Revoke  — owner/manager removes the device from the dashboard, or the
 *                device unregisters itself.
 *
 * Results carry an `http` key that the controller turns into the status code.
 */
class PosDeviceService
{
    public function __construct(
        private readonly PosDeviceRepository $repo,
        private readonly StaffPinService $pins
    ) {}

    // ── Setup (owner / manager token) ────────────────────────────────────

    public function registerableBranches(User $user): array
    {
        return [
            'success'  => true,
            'branches' => $this->repo->findRegisterableBranches($user)->map(fn (CafeBranch $b) => [
                'uuid'        => $b->uuid,
                'branch_name' => $b->branch_name,
                'address'     => $b->address,
            ])->values(),
        ];
    }

    public function register(User $user, string $branchUuid, string $name): array
    {
        $branch = $this->repo->findRegisterableBranches($user)->firstWhere('uuid', $branchUuid);

        if (! $branch) {
            return ['success' => false, 'http' => 403, 'message' => 'You can\'t set up a register for this branch.'];
        }

        $device = $this->repo->create($branch->branch_id, $name, $user->user_id);
        $token  = $device->createToken('pos-device')->plainTextToken;

        // The setup login on this device is no longer needed — drop it so the
        // manager/owner isn't left signed in on a shared counter device.
        $setupToken = $user->currentAccessToken();

        if ($setupToken instanceof PersonalAccessToken) {
            $setupToken->delete();
        }

        Log::channel('auth')->info('POS device registered.', [
            'device_uuid' => $device->uuid,
            'branch_uuid' => $branch->uuid,
            'by_uuid'     => $user->uuid,
            'by_role'     => $user->roleName(),
        ]);

        return [
            'success'      => true,
            'http'         => 201,
            'message'      => "Register \"{$name}\" is set up for {$branch->branch_name}.",
            'device_token' => $token,
            'token_type'   => 'Bearer',
            'device'       => new PosDeviceResource($device->load(['branch', 'registeredBy'])),
        ];
    }

    // ── Dashboard: manage a branch's registers ───────────────────────────

    public function listForBranch(CafeBranch $branch): array
    {
        return [
            'success' => true,
            'devices' => PosDeviceResource::collection($this->repo->listForBranch($branch->branch_id)),
        ];
    }

    public function revokeForBranch(User $actor, CafeBranch $branch, string $deviceUuid): array
    {
        $device = $this->repo->findForBranch($deviceUuid, $branch->branch_id);

        if (! $device) {
            return ['success' => false, 'http' => 404, 'message' => 'Register not found.'];
        }

        $this->repo->revoke($device);

        Log::channel('auth')->info('POS device removed from dashboard.', [
            'device_uuid' => $device->uuid,
            'branch_uuid' => $branch->uuid,
            'by_uuid'     => $actor->uuid,
        ]);

        return ['success' => true, 'message' => "Register \"{$device->name}\" removed. It will need to be set up again."];
    }

    // ── On the device (device token) ─────────────────────────────────────

    public function current(PosDevice $device): array
    {
        return [
            'success' => true,
            'device'  => new PosDeviceResource($device->load(['branch', 'registeredBy', 'activeStaff.user'])),
        ];
    }

    public function lockScreenStaff(PosDevice $device): array
    {
        return [
            'success' => true,
            'staff'   => $this->repo->findUnlockableStaff($device->branch_id)->map(fn (CafeStaff $a) => [
                'uuid'       => $a->user->uuid,
                'firstname'  => $a->user->firstname,
                'lastname'   => $a->user->lastname,
                'role'       => $a->user->roleName(),
                'pin_set'    => $a->user->hasPin(),
                'pin_locked' => $a->user->isPinLocked(),
            ])->values(),
        ];
    }

    public function unlock(PosDevice $device, string $userUuid, string $pin): array
    {
        $assignment = $this->repo->findUnlockableAssignment($userUuid, $device->branch_id);

        if (! $assignment) {
            return ['success' => false, 'http' => 404, 'message' => 'This person is not active at this branch.'];
        }

        $check = $this->pins->verify($assignment->user, $pin);

        if (! $check['success']) {
            return [
                ...$check,
                'http' => ! empty($check['locked']) ? 423 : 422,
            ];
        }

        $this->repo->setActiveStaff($device, $assignment);

        Log::channel('auth')->info('POS unlocked.', [
            'device_uuid' => $device->uuid,
            'staff_uuid'  => $assignment->user->uuid,
        ]);

        return [
            'success' => true,
            'message' => "Welcome, {$assignment->user->firstname}.",
            'staff'   => [
                'uuid'      => $assignment->user->uuid,
                'firstname' => $assignment->user->firstname,
                'lastname'  => $assignment->user->lastname,
                'role'      => $assignment->user->roleName(),
            ],
        ];
    }

    public function lock(PosDevice $device): array
    {
        $this->repo->setActiveStaff($device, null);

        return ['success' => true, 'message' => 'Register locked.'];
    }

    public function unregisterSelf(PosDevice $device): array
    {
        $this->repo->revoke($device);

        Log::channel('auth')->info('POS device unregistered itself.', ['device_uuid' => $device->uuid]);

        return ['success' => true, 'message' => 'This device is no longer a register.'];
    }
}
