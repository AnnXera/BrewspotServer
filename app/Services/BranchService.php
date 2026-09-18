<?php

namespace App\Services;

use App\Http\Resources\CafeBranchResource;
use App\Models\User;

use App\Repository\BranchRepository;
use App\Repository\SubscriptionRepository;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BranchService
{
    /**
     * Owner statuses that allow editing and deleting.
     * Pending/rejected/suspended branches are admin-controlled.
     */
    private const OWNER_EDITABLE_STATUSES = ['active', 'inactive'];

    /**
     * Allowed owner-driven status transitions.
     */
    private const OWNER_STATUS_TRANSITIONS = [
        'active'   => ['inactive'],
        'inactive' => ['active'],
    ];

    public function __construct(
        private readonly BranchRepository $repo,
        private readonly SubscriptionRepository $subscriptionRepo
    ) {}

    public function createBranch(User $owner, array $payload): array
    {
        $cafe = $owner->cafes()->first();

        if (! $cafe) {
            Log::channel('owner')->warning('Branch creation blocked — owner has no cafe on record.', [
                'owner_uuid' => $owner->uuid,
            ]);

            return ['success' => false, 'message' => 'No cafe found for this account.'];
        }

        $subscription = $this->subscriptionRepo->findCurrentByUserId($owner->user_id);

        if (! $subscription) {
            Log::channel('owner')->warning('Branch creation blocked — no active subscription.', [
                'owner_uuid' => $owner->uuid,
            ]);

            return [
                'success' => false,
                'message' => 'You need an active subscription plan before adding branches.',
            ];
        }

        $plan = $subscription->plan;

        if (! $plan || ! $plan->load('features')->hasFeature('multi_branch')) {
            Log::channel('owner')->warning('Branch creation blocked — plan does not include multi_branch feature.', [
                'owner_uuid' => $owner->uuid,
                'plan'       => $plan?->sub_name,
            ]);

            return [
                'success' => false,
                'message' => "Your current plan ({$plan?->sub_name}) does not support multiple branches. Upgrade to Premium or higher to add more branches.",
            ];
        }

        $result = [];

        try {
            DB::transaction(function () use ($owner, $cafe, $payload, &$result) {

                $branch       = $this->repo->create($cafe->cafe_id, $payload);
                $branchFolder = "users/{$owner->uuid}/cafes/{$cafe->uuid}/branches/{$branch->uuid}";

                // Cafe picture — PUBLIC (meant to be displayed)
                if (isset($payload['cafe_picture'])) {
                    $picturePath = $this->storeFile(
                        $payload['cafe_picture'],
                        "{$branchFolder}/cafe_pictures",
                        'public'
                    );
                    $branch->update(['cafe_picture' => $picturePath]);
                }

                // Permits — PRIVATE
                $birPath      = $this->storeFile($payload['bir_file'],             "{$branchFolder}/branch_documents");
                $mayorsPath   = $this->storeFile($payload['mayors_permit_file'],   "{$branchFolder}/branch_documents");
                $sanitaryPath = $this->storeFile($payload['sanitary_permit_file'], "{$branchFolder}/branch_documents");

                $this->repo->createDocument($branch->branch_id, 'BIR',             $birPath);
                $this->repo->createDocument($branch->branch_id, 'mayors_permit',   $mayorsPath);
                $this->repo->createDocument($branch->branch_id, 'sanitary_permit', $sanitaryPath);

                $this->repo->createApprovalEntry($owner->user_id, $cafe->cafe_id, $branch->branch_id);

                Log::channel('owner')->info('Side branch submitted for approval.', [
                    'owner_uuid'  => $owner->uuid,
                    'cafe_uuid'   => $cafe->uuid,
                    'branch_uuid' => $branch->uuid,
                ]);

                $result = [
                    'success' => true,
                    'message' => 'Branch submitted. Please wait for admin approval.',
                    'branch'  => new CafeBranchResource($branch->load('documents')),
                ];
            });

            return $result;

        } catch (\Throwable $e) {
            Log::channel('owner')->error('Branch creation failed.', [
                'owner_uuid' => $owner->uuid,
                'error'      => $e->getMessage(),
                'line'       => $e->getLine(),
                'file'       => $e->getFile(),
            ]);

            return [
                'success' => false,
                'message' => 'Something went wrong while adding the branch. Please try again.',
            ];
        }
    }

    /**
     * Update branch details and/or toggle status (active ↔ inactive).
     * Only branches with an owner-editable status can be updated.
     */
    public function updateBranch(User $owner, string $branchUuid, array $payload): array
    {
        $branch = $this->repo->findByUuidForOwner($owner->user_id, $branchUuid);

        if (! $branch) {
            return ['success' => false, 'message' => 'Branch not found.'];
        }

        if (! in_array($branch->status, self::OWNER_EDITABLE_STATUSES, true)) {
            Log::channel('owner')->warning('Branch update blocked — status not editable by owner.', [
                'owner_uuid'  => $owner->uuid,
                'branch_uuid' => $branch->uuid,
                'status'      => $branch->status,
            ]);

            return [
                'success' => false,
                'message' => "Cannot edit a branch with status '{$branch->status}'.",
            ];
        }

        // Validate status transition if a new status was requested
        if (isset($payload['status'])) {
            $allowed = self::OWNER_STATUS_TRANSITIONS[$branch->status] ?? [];

            if (! in_array($payload['status'], $allowed, true)) {
                return [
                    'success' => false,
                    'message' => "Cannot change status from '{$branch->status}' to '{$payload['status']}'.",
                ];
            }
        }

        try {
            $result = [];

            DB::transaction(function () use ($owner, $branch, $payload, &$result) {
                $cafe         = $branch->cafe;
                $branchFolder = "users/{$owner->uuid}/cafes/{$cafe->uuid}/branches/{$branch->uuid}";

                // Collect basic field updates
                $updates = array_filter([
                    'branch_name'      => $payload['branch_name'] ?? null,
                    'cafe_email'       => $payload['cafe_email'] ?? null,
                    'cafe_phonenumber' => $payload['cafe_phonenumber'] ?? null,
                    'address'          => $payload['address'] ?? null,
                    'status'           => $payload['status'] ?? null,
                ], fn ($v) => $v !== null);

                // Cafe picture — replace on public disk
                if (isset($payload['cafe_picture'])) {
                    if ($branch->cafe_picture) {
                        Storage::disk('public')->delete($branch->cafe_picture);
                    }

                    $updates['cafe_picture'] = $this->storeFile(
                        $payload['cafe_picture'],
                        "{$branchFolder}/cafe_pictures",
                        'public'
                    );
                }

                if (! empty($updates)) {
                    $branch = $this->repo->update($branch, $updates);
                }

                // Permit documents — replace on local (private) disk
                $documentMap = [
                    'bir_file'             => 'BIR',
                    'mayors_permit_file'   => 'mayors_permit',
                    'sanitary_permit_file' => 'sanitary_permit',
                ];

                foreach ($documentMap as $fieldName => $docType) {
                    if (isset($payload[$fieldName])) {
                        // Delete old file from storage
                        $existingDoc = $branch->documents->firstWhere('doc_type', $docType);
                        if ($existingDoc && $existingDoc->file) {
                            Storage::disk('local')->delete($existingDoc->file);
                        }

                        $newPath = $this->storeFile(
                            $payload[$fieldName],
                            "{$branchFolder}/branch_documents"
                        );

                        $this->repo->updateDocument($branch->branch_id, $docType, $newPath);
                    }
                }

                Log::channel('owner')->info('Branch updated.', [
                    'owner_uuid'     => $owner->uuid,
                    'branch_uuid'    => $branch->uuid,
                    'updated_fields' => array_keys($updates),
                ]);

                $result = [
                    'success' => true,
                    'message' => 'Branch updated successfully.',
                    'branch'  => new CafeBranchResource($branch->fresh('documents')),
                ];
            });

            return $result;

        } catch (\Throwable $e) {
            Log::channel('owner')->error('Branch update failed.', [
                'owner_uuid'  => $owner->uuid,
                'branch_uuid' => $branchUuid,
                'error'       => $e->getMessage(),
                'line'        => $e->getLine(),
                'file'        => $e->getFile(),
            ]);

            return [
                'success' => false,
                'message' => 'Something went wrong while updating the branch. Please try again.',
            ];
        }
    }

    /**
     * Soft-delete a branch. Only side branches with an owner-editable status
     * can be deleted. The main branch is protected.
     */
    public function deleteBranch(User $owner, string $branchUuid): array
    {
        $branch = $this->repo->findByUuidForOwner($owner->user_id, $branchUuid);

        if (! $branch) {
            return ['success' => false, 'message' => 'Branch not found.'];
        }

        if ($branch->branch_type === 'main') {
            Log::channel('owner')->warning('Branch deletion blocked — cannot delete main branch.', [
                'owner_uuid'  => $owner->uuid,
                'branch_uuid' => $branch->uuid,
            ]);

            return [
                'success' => false,
                'message' => 'The main branch cannot be deleted.',
            ];
        }

        if (! in_array($branch->status, self::OWNER_EDITABLE_STATUSES, true)) {
            Log::channel('owner')->warning('Branch deletion blocked — status not deletable by owner.', [
                'owner_uuid'  => $owner->uuid,
                'branch_uuid' => $branch->uuid,
                'status'      => $branch->status,
            ]);

            return [
                'success' => false,
                'message' => "Cannot delete a branch with status '{$branch->status}'.",
            ];
        }

        // Soft-delete documents first, then the branch
        $branch->documents()->delete();
        $branch->delete();

        Log::channel('owner')->info('Branch soft-deleted.', [
            'owner_uuid'  => $owner->uuid,
            'branch_uuid' => $branch->uuid,
            'branch_name' => $branch->branch_name,
        ]);

        return [
            'success' => true,
            'message' => "Branch '{$branch->branch_name}' has been deleted.",
        ];
    }

    /**
     * @param string $disk 'local' (private, default) or 'public'
     */
    private function storeFile(UploadedFile $file, string $path, string $disk = 'local'): string
    {
        return $file->store($path, $disk);
    }
}