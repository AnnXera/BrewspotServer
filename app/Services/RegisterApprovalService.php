<?php

namespace App\Services;

use App\Models\PosDevice;
use App\Models\User;
use App\Repository\PosDeviceRepository;

/**
 * A manager or owner approving something on the register with their PIN (refunds,
 * voids, spoilage). The approver must be an active manager at the register's branch
 * or the cafe owner, with a PIN that isn't temporary or locked.
 *
 * Results carry an `http` key on failure; on success, `user` is the approver.
 */
class RegisterApprovalService
{
    public function __construct(
        private readonly PosDeviceRepository $repo,
        private readonly StaffPinService $pins,
        private readonly BranchAlertService $alerts,
    ) {}

    /**
     * @param  string  $action  what is being approved, plural: "refunds", "voids", "spoilage"
     * @return array{success: true, user: User}|array{success: false, http: int, message: string, attempts_left?: int|null}
     */
    public function approve(PosDevice $device, string $managerUuid, string $pin, string $action): array
    {
        $approver = $this->repo->findActiveUserByUuid($managerUuid);

        if (! $approver) {
            return ['success' => false, 'http' => 404, 'message' => 'Manager account not found.'];
        }

        $isBranchManager = $this->repo->isActiveBranchManager($device->branch_id, $approver->user_id);
        $isCafeOwner = ($device->branch?->cafe?->owner_id === $approver->user_id) || $approver->isOwner();

        if (! $isBranchManager && ! $isCafeOwner) {
            return ['success' => false, 'http' => 403, 'message' => "Only an authorized Manager or Cafe Owner can approve {$action}."];
        }

        if ($approver->mustChangePin()) {
            return ['success' => false, 'http' => 409, 'message' => "Manager must change their temporary PIN before approving {$action}."];
        }

        $check = $this->pins->verify($approver, $pin);

        if (! $check['success']) {
            if (! empty($check['just_locked'])) {
                $this->alerts->pinLocked($device->branch, $approver);
            }

            return [
                'success'       => false,
                'http'          => ! empty($check['locked']) ? 423 : 422,
                'message'       => $check['message'] ?? 'Incorrect manager PIN.',
                'attempts_left' => $check['attempts_left'] ?? null,
            ];
        }

        return ['success' => true, 'user' => $approver];
    }
}
