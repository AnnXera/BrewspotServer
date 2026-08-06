<?php

namespace App\Services;

use App\Mail\OwnerStatusMail;
use App\Http\Resources\UserResource;
use App\Http\Resources\ApprovalListResource;
use App\Repository\OwnerManagementRepository;
use Illuminate\Support\Facades\Log;
use App\Contracts\MailAdapterInterface;

class OwnerManagementService
{
    public function __construct(
        private readonly OwnerManagementRepository $repo,
        private readonly MailAdapterInterface $mailer
    ) {}

    /**
     * List all owners with minimal info.
     */
    public function listOwners(int $perPage = 15)
    {
        $owners = $this->repo->listOwners($perPage);

        return $owners->through(function ($owner) {
            $latestSub = $owner->subscriptions->first();

            return [
                'uuid'         => $owner->uuid,
                'name'         => trim("{$owner->firstname} {$owner->lastname}"),
                'email'        => $owner->email,
                'phone_number' => $owner->phone_number,
                'status'       => $owner->status,
                'subscription' => $latestSub?->plan->sub_name,
                'date_joined'  => $owner->created_at?->toISOString(),
            ];
        });
    }

    /**
     * Get full owner profile with cafes and branches.
     */
    public function getOwnerDetails(string $uuid): array
    {
        $owner = $this->repo->findOwnerByUuid($uuid);

        return [
            'success' => true,
            'owner'   => new UserResource($owner),
            'cafes'   => $owner->cafes->map(function ($cafe) {
                return [
                    'uuid'      => $cafe->uuid,
                    'cafe_name' => $cafe->cafe_name,
                    'documents' => $cafe->documents->map(fn ($doc) => [
                        'cafe_doc_id'  => $doc->cafe_doc_id,
                        'doc_type'     => $doc->doc_type,
                        'download_url' => "/api/documents/cafe/{$doc->cafe_doc_id}",
                    ]),
                    'branches'  => $cafe->branches->map(fn ($branch) => [
                        'uuid'             => $branch->uuid,
                        'branch_name'      => $branch->branch_name,
                        'cafe_picture'     => $branch->cafe_picture,
                        'cafe_email'       => $branch->cafe_email,
                        'cafe_phonenumber' => $branch->cafe_phonenumber,
                        'address'          => $branch->address,
                        'branch_type'      => $branch->branch_type,
                        'status'           => $branch->status,
                        'documents'        => $branch->documents->map(fn ($doc) => [
                            'branch_doc_id' => $doc->branch_doc_id,
                            'doc_type'      => $doc->doc_type,
                            'download_url'  => "/api/documents/branch/{$doc->branch_doc_id}",
                        ]),
                    ]),
                ];
            }),
        ];
    }

    /**
     * Update owner status, cascade to approval_list and branches, and
     * send notification email.
     *
     * Cascade rules:
     * - status = 'inactive' -> all currently 'active' branches become 'inactive'
     * - status = 'active'   -> all currently 'inactive' branches are restored to 'active'
     * Branches sitting in 'pending_approval' or 'rejected' are left untouched
     * either way, since they aren't operating regardless of the owner's status.
     */
    public function updateStatus(string $uuid, string $status, int $reviewerId): array
    {
        $owner = $this->repo->findOwnerByUuid($uuid);

        if (!$owner) {
            return [
                'success' => false,
            'message' => 'Owner not found.',
        ];
    }

    $oldStatus = $owner->status;
    $owner     = $this->repo->updateStatus($owner, $status);

    $branchesCascaded = 0;

    if ($status === 'inactive') {
        $branchesCascaded = $this->repo->cascadeBranchStatus($owner, 'active', 'inactive');

        Log::channel('admin')->info('Owner suspended — cascaded to branches.', [
            'owner_uuid'         => $owner->uuid,
            'branches_suspended' => $branchesCascaded,
        ]);
    } elseif ($status === 'active') {
        $branchesCascaded = $this->repo->cascadeBranchStatus($owner, 'inactive', 'active');

        Log::channel('admin')->info('Owner reactivated — cascaded to branches.', [
            'owner_uuid'           => $owner->uuid,
            'branches_reactivated' => $branchesCascaded,
        ]);
    }

    // approval_lists tracks the original application review decision
    // (approved/rejected), not ongoing account operational status.
    // Suspending/reinstating an already-approved owner should not
    // overwrite that historical review record.
    $approval = null;

    if (in_array($status, ['approved', 'rejected'], true)) {
        $approval = $this->repo->findLatestApproval($owner->user_id);

        if ($approval) {
            $approval = $this->repo->updateApproval($approval, $status, $reviewerId);
        }
    }

    $this->mailer->sendMailable($owner->email, new OwnerStatusMail($owner->firstname, $status, $owner->uuid));

    Log::channel('admin')->info('Owner status updated.', [
        'owner_uuid'        => $owner->uuid,
        'old_status'        => $oldStatus,
        'new_status'        => $status,
        'approval_id'       => $approval?->approval_id,
        'branches_cascaded' => $branchesCascaded,
        'reviewed_by'       => $reviewerId,
    ]);

    return [
        'success'  => true,
        'message'  => "Owner status updated to '{$status}' and notification email sent.",
        'owner'    => new UserResource($owner->load('cafes.branches')),
        'approval' => $approval ? new ApprovalListResource($approval->load(['user', 'cafe', 'branch', 'reviewer'])) : null,
    ];
}

    /**
     * List all approval entries for admin review screen.
     */
    public function listApprovals(int $perPage = 15, ?string $status = null)
    {
        $approvals = $this->repo->listApprovals($perPage, $status);

        return $approvals->through(fn ($approval) => new ApprovalListResource($approval));
    }
}