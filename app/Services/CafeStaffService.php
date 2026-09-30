<?php

namespace App\Services;

use App\Contracts\MailAdapterInterface;

use App\Http\Resources\CafeStaffResource;

use App\Mail\StaffAccountCreatedMail;

use App\Models\User;

use App\Repository\CafeStaffRepository;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CafeStaffService
{
    public function __construct(
        private readonly CafeStaffRepository $repo,
        private readonly StaffPinService $pins,
        private readonly MailAdapterInterface $mailer
    ) {}

    public function createStaff(User $owner, array $payload): array
    {
        $cafe = $this->repo->findCafeByOwner($owner->user_id);

        if (! $cafe) {
            return ['success' => false, 'message' => 'No cafe found for this account.'];
        }

        $branches = $this->repo->findOwnedBranches($cafe->cafe_id, $payload['branch_uuids']);

        if ($branches->count() !== count(array_unique($payload['branch_uuids']))) {
            Log::channel('owner')->warning('Staff creation blocked — one or more branches not owned by this cafe.', [
                'owner_uuid' => $owner->uuid,
            ]);

            return ['success' => false, 'message' => 'One or more selected branches do not belong to your cafe.'];
        }

        $role = $this->repo->findRoleByName($payload['role']);

        if (! $role) {
            return ['success' => false, 'message' => 'Invalid role selected.'];
        }

        $result = [];

        try {
            DB::transaction(function () use ($owner, $payload, $branches, $role, $cafe, &$result) {

                $staffUser = $this->repo->createStaffUser($payload, $role);

                // Cashiers only — managers set their own PIN during password
                // setup, and Staff have none (the request drops it for both).
                if (isset($payload['pin'])) {
                    $this->pins->setPinFor($staffUser, $payload['pin'], $owner);
                }

                foreach ($branches as $branch) {
                    $this->repo->assignToBranch(
                        $staffUser->user_id,
                        $branch->branch_id,
                        $payload['hired_at'] ?? null
                    );
                }

                // Only managers get a dashboard password; cashiers use their PIN on the POS.
                if ($role->role_name === 'Manager') {
                    $this->mailer->sendMailable($staffUser->email, new StaffAccountCreatedMail(
                        firstname: $staffUser->firstname,
                        roleName: $role->role_name,
                        staffUuid: $staffUser->uuid,
                    ));
                }

                Log::channel('owner')->info('Staff account created.', [
                    'owner_uuid' => $owner->uuid,
                    'staff_uuid' => $staffUser->uuid,
                    'role'       => $role->role_name,
                    'branches'   => $branches->pluck('uuid'),
                ]);

                $staffUser = $this->repo->findStaffUserForCafe($staffUser->uuid, $cafe->cafe_id);

                $result = [
                    'success' => true,
                    'message' => $role->role_name === 'Manager'
                        ? 'Staff account created. An email has been sent so they can set up their password.'
                        : 'Cashier account created. They can sign in on the branch register with their PIN.',
                    'staff'   => new CafeStaffResource($staffUser),
                ];
            });

            return $result;

        } catch (\Throwable $e) {
            Log::channel('owner')->error('Staff creation failed.', [
                'owner_uuid' => $owner->uuid,
                'error'      => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Something went wrong while creating the staff account. Please try again.',
            ];
        }
    }

    public function listStaff(User $owner, int $perPage = 15): array
    {
        $cafe = $this->repo->findCafeByOwner($owner->user_id);

        if (! $cafe) {
            return ['success' => false, 'message' => 'No cafe found for this account.'];
        }

        $staff = $this->repo->listByCafe($cafe->cafe_id, $perPage);

        return [
            'success' => true,
            'staff'   => $staff->through(fn ($user) => new CafeStaffResource($user)),
        ];
    }

    /**
     * Terminates every branch assignment for this staff member and revokes
     * their sessions. The user account itself isn't deleted, so login
     * history, past transactions, etc. stay intact for audit purposes.
     */
    public function removeStaff(User $owner, string $staffUuid): array
    {
        $cafe = $this->repo->findCafeByOwner($owner->user_id);

        if (! $cafe) {
            return ['success' => false, 'message' => 'No cafe found for this account.'];
        }

        $staffUser = $this->repo->findStaffUserForCafe($staffUuid, $cafe->cafe_id);

        if (! $staffUser) {
            return ['success' => false, 'message' => 'Staff member not found.'];
        }

        DB::transaction(function () use ($staffUser) {
            $this->repo->terminateAllAssignments($staffUser->user_id);
            $this->repo->revokeAccess($staffUser);
        });

        Log::channel('owner')->info('Staff terminated at all branches.', [
            'owner_uuid' => $owner->uuid,
            'staff_uuid' => $staffUser->uuid,
        ]);

        return ['success' => true, 'message' => 'Staff member terminated at all branches.'];
    }
}