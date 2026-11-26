<?php

namespace App\Services;

use App\Http\Resources\CafeBranchResource;
use App\Models\ActivityLog;
use App\Models\CafeUpdateRequest;
use App\Models\User;
use App\Notifications\BranchSubmittedForApproval;

use App\Repository\BranchRepository;
use App\Repository\CafeUpdateRepository;
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
        private readonly SubscriptionRepository $subscriptionRepo,
        private readonly NotificationService $notifications,
        private readonly ActivityLogService $activity,
        private readonly BranchAlertService $alerts,
        private readonly CafeUpdateRepository $cafeUpdates
    ) {}

    /**
     * A branch's COR carries the cafe name, so while a name change is under review a new
     * or resubmitted branch would be registered under a name that is about to change.
     */
    private const NAME_CHANGE_PENDING = 'Your cafe name change is still being reviewed. Add or resubmit branches after it is decided, so their COR shows the right name.';

    /** Contact fields the owner edits without admin review, with their audit-log labels. */
    private const CONTACT_FIELDS = [
        'cafe_email'       => 'Email',
        'cafe_phonenumber' => 'Phone number',
    ];

    public function createBranch(User $owner, array $payload): array
    {
        $cafe = $owner->cafes()->first();

        if (! $cafe) {
            Log::channel('owner')->warning('Branch creation blocked — owner has no cafe on record.', [
                'owner_uuid' => $owner->uuid,
            ]);

            return ['success' => false, 'message' => 'No cafe found for this account.'];
        }

        if ($blocked = $this->multiBranchBlock($owner)) {
            return ['success' => false, 'message' => $blocked];
        }

        if ($this->cafeUpdates->hasPendingForCafe($cafe->cafe_id, CafeUpdateRequest::TYPE_NAME_CHANGE)) {
            return ['success' => false, 'message' => self::NAME_CHANGE_PENDING];
        }

        $result = [];
        $createdBranch = null;
        $createdApproval = null;

        try {
            DB::transaction(function () use ($owner, $cafe, $payload, &$result, &$createdBranch, &$createdApproval) {

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
                $birPath = $this->storeFile($payload['bir_file'], "{$branchFolder}/branch_documents");

                $this->repo->createDocument($branch->branch_id, 'BIR', $birPath, [
                    'registered_at' => $payload['bir_registered_at'],
                    'expired_at'    => $payload['bir_expired_at'] ?? null,
                    'tin_number'    => $payload['tin_number'],
                    'vat'           => $payload['vat'],
                ]);

                $createdApproval = $this->repo->createApprovalEntry($owner->user_id, $cafe->cafe_id, $branch->branch_id);
                $createdBranch   = $branch;

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

            // Let admins know there's a branch waiting in Approvals (after commit, so it can't be rolled back)
            if ($createdBranch && $createdApproval) {
                $this->notifications->notifyAdmins(
                    new BranchSubmittedForApproval($owner, $cafe, $createdBranch, $createdApproval)
                );
            }

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
     * Why the owner can't submit a side branch right now (no running subscription, or a
     * plan without multi_branch), or null when they can.
     */
    private function multiBranchBlock(User $owner): ?string
    {
        $subscription = $this->subscriptionRepo->findCurrentByUserId($owner->user_id);

        if (! $subscription) {
            Log::channel('owner')->warning('Branch submission blocked — no active subscription.', [
                'owner_uuid' => $owner->uuid,
            ]);

            return 'You need an active subscription plan before adding branches.';
        }

        $plan = $subscription->plan;

        if (! $plan || ! $plan->load('features')->hasFeature('multi_branch')) {
            Log::channel('owner')->warning('Branch submission blocked — plan does not include multi_branch feature.', [
                'owner_uuid' => $owner->uuid,
                'plan'       => $plan?->sub_name,
            ]);

            return "Your current plan ({$plan?->sub_name}) does not support multiple branches. Upgrade to Premium or higher to add more branches.";
        }

        return null;
    }

    /**
     * An owner corrects a rejected side branch and sends it back for review. Only
     * allowed when the admin let them resubmit. The branch keeps its uuid; a new
     * approval entry is added, so the history shows both the rejection and this.
     */
    public function resubmitBranch(User $owner, string $branchUuid, array $payload): array
    {
        $branch = $this->repo->findByUuidForOwner($owner->user_id, $branchUuid);

        if (! $branch) {
            return ['success' => false, 'message' => 'Branch not found.'];
        }

        $latest = $this->repo->latestApproval($branch->branch_id);

        if ($branch->branch_type !== 'side' || $branch->status !== 'rejected' || $latest?->status !== 'rejected') {
            return ['success' => false, 'message' => 'Only a rejected branch can be resubmitted.'];
        }

        if (! $latest->can_resubmit) {
            return ['success' => false, 'message' => 'This rejection is final, so the branch cannot be resubmitted.'];
        }

        if ($blocked = $this->multiBranchBlock($owner)) {
            return ['success' => false, 'message' => $blocked];
        }

        if ($this->cafeUpdates->hasPendingForCafe($branch->cafe_id, CafeUpdateRequest::TYPE_NAME_CHANGE)) {
            return ['success' => false, 'message' => self::NAME_CHANGE_PENDING];
        }

        if (! isset($payload['bir_file']) && ! $branch->documents->firstWhere('doc_type', 'BIR')) {
            return [
                'success' => false,
                'message' => 'Please upload the BIR certificate for this branch.',
                'errors'  => ['bir_file' => ['BIR file is required.']],
            ];
        }

        $cafe = $branch->cafe;
        $approval = null;

        try {
            DB::transaction(function () use ($owner, $cafe, $branch, $payload, &$approval) {
                $branchFolder = "users/{$owner->uuid}/cafes/{$cafe->uuid}/branches/{$branch->uuid}";

                $updates = [
                    'branch_name'      => $payload['branch_name'],
                    'address'          => $payload['address'],
                    'cafe_email'       => $payload['cafe_email'],
                    'cafe_phonenumber' => $payload['cafe_phonenumber'],
                    'status'           => 'pending_approval',
                ];

                if (isset($payload['cafe_picture'])) {
                    if ($branch->cafe_picture) {
                        Storage::disk('public')->delete($branch->cafe_picture);
                    }
                    $updates['cafe_picture'] = $this->storeFile($payload['cafe_picture'], "{$branchFolder}/cafe_pictures", 'public');
                }

                $this->repo->update($branch, $updates);

                // BIR details are always resent; the file only when a new one was chosen.
                $birDetails = [
                    'registered_at' => $payload['bir_registered_at'],
                    'expired_at'    => $payload['bir_expired_at'] ?? null,
                    'tin_number'    => $payload['tin_number'],
                    'vat'           => $payload['vat'],
                ];
                $existingBir = $branch->documents->firstWhere('doc_type', 'BIR');

                if (isset($payload['bir_file'])) {
                    if ($existingBir?->file) {
                        Storage::disk('local')->delete($existingBir->file);
                    }
                    $path = $this->storeFile($payload['bir_file'], "{$branchFolder}/branch_documents");
                    $this->repo->updateDocument($branch->branch_id, 'BIR', $path, $birDetails);
                } else {
                    $existingBir->update($birDetails); // checked above: one exists
                }

                $approval = $this->repo->createApprovalEntry($owner->user_id, $cafe->cafe_id, $branch->branch_id);
            });
        } catch (\Throwable $e) {
            Log::channel('owner')->error('Branch resubmission failed.', [
                'owner_uuid'  => $owner->uuid,
                'branch_uuid' => $branchUuid,
                'error'       => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => 'Something went wrong while resubmitting the branch. Please try again.'];
        }

        $branch->refresh();

        Log::channel('owner')->info('Side branch resubmitted for approval.', [
            'owner_uuid'  => $owner->uuid,
            'branch_uuid' => $branch->uuid,
        ]);

        $this->notifications->notifyAdmins(new BranchSubmittedForApproval($owner, $cafe, $branch, $approval, resubmitted: true));

        return [
            'success' => true,
            'message' => 'Branch resubmitted. Please wait for admin approval.',
            'branch'  => new CafeBranchResource($branch->load('documents')),
        ];
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

        // What changed in the contact details, for the activity log and the managers' notice.
        $changes = [];
        foreach (self::CONTACT_FIELDS as $field => $label) {
            if (isset($payload[$field]) && $payload[$field] !== $branch->{$field}) {
                $changes[] = ['field' => $label, 'before' => $branch->{$field}, 'after' => $payload[$field]];
            }
        }
        if (isset($payload['cafe_picture'])) {
            $changes[] = ['field' => 'Picture'];
        }

        try {
            $result = [];

            DB::transaction(function () use ($owner, $branch, $payload, &$result) {
                $cafe         = $branch->cafe;
                $branchFolder = "users/{$owner->uuid}/cafes/{$cafe->uuid}/branches/{$branch->uuid}";

                // Collect basic field updates
                // Name and address are deliberately absent (BIR-registered; admin-only).
                $updates = array_filter([
                    'cafe_email'       => $payload['cafe_email'] ?? null,
                    'cafe_phonenumber' => $payload['cafe_phonenumber'] ?? null,
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
                    'bir_file' => 'BIR',
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

                        if ($docType === 'BIR') {
                            $extraData = [];
                            if (isset($payload['bir_registered_at'])) $extraData['registered_at'] = $payload['bir_registered_at'];
                            if (isset($payload['tin_number'])) $extraData['tin_number'] = $payload['tin_number'];
                            if (isset($payload['vat'])) $extraData['vat'] = $payload['vat'];

                            $this->repo->updateDocument($branch->branch_id, $docType, $newPath, $extraData);
                        } else {
                            $this->repo->updateDocument($branch->branch_id, $docType, $newPath);
                        }
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

            // After commit, so a rolled-back edit is never logged or announced.
            if ($changes) {
                $lower = array_map('strtolower', array_column($changes, 'field'));

                $this->activity->branch($owner, $branch, ActivityLog::BRANCH_DETAILS_UPDATED,
                    'Updated branch ' . implode(', ', $lower), ['changes' => $changes]);
                $this->alerts->branchDetailsUpdated($branch, $owner, $lower);
            }

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

        // Besides active/inactive, an owner may withdraw a branch still under review
        // or remove one that was rejected. Suspended branches stay admin-controlled.
        $deletable = [...self::OWNER_EDITABLE_STATUSES, 'pending_approval', 'rejected'];

        if (! in_array($branch->status, $deletable, true)) {
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

        $withdrawn = $branch->status === 'pending_approval';

        DB::transaction(function () use ($branch, $withdrawn) {
            // A pending review is closed as withdrawn so it leaves the admin's queue
            // but stays in the owner's application history.
            if ($withdrawn) {
                $pending = $this->repo->latestApproval($branch->branch_id);
                if ($pending?->status === 'pending_approval') {
                    $pending->update(['status' => 'withdrawn']);
                }
            }

            // Soft-delete documents first, then the branch
            $branch->documents()->delete();
            $branch->delete();
        });

        Log::channel('owner')->info('Branch soft-deleted.', [
            'owner_uuid'  => $owner->uuid,
            'branch_uuid' => $branch->uuid,
            'branch_name' => $branch->branch_name,
        ]);

        return [
            'success' => true,
            'message' => $withdrawn
                ? "Branch '{$branch->branch_name}' was withdrawn from review and removed."
                : "Branch '{$branch->branch_name}' has been deleted.",
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