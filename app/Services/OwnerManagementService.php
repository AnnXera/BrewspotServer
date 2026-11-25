<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\ModelNotFoundException;

use App\Mail\OwnerStatusMail;
use App\Mail\BranchStatusMail;
use App\Notifications\OwnerNotice;

use App\Http\Resources\UserResource;
use App\Http\Resources\ApprovalListResource;
use App\Http\Resources\CafeBranchResource;

use App\Repository\OwnerManagementRepository;
use App\Repository\CafeUpdateRepository;

use App\Contracts\MailAdapterInterface;

class OwnerManagementService
{
    /**
     * Valid current-status → allowed-new-status transitions for admin actions.
     * 'inactive' is intentionally absent as a target — that's owner self-service only.
     */
    private const ALLOWED_TRANSITIONS = [
        'pending_approval' => ['approved', 'rejected'],
        'active'            => ['suspended'],
        'suspended'         => ['active']
    ];

    public function __construct(
        private readonly OwnerManagementRepository $repo,
        private readonly MailAdapterInterface $mailer,
        private readonly NotificationService $notifications,
        private readonly CafeUpdateRepository $cafeUpdates
    ) {}

    /**
     * List all owners with minimal info, filtered.
     */
    public function listOwners(int $perPage = 15, ?string $search = null, ?string $status = null, ?string $date = null)
    {
        $owners = $this->repo->listOwners($perPage, $search, $status, $date);

        return $owners->through(function ($owner) {
            $latestSub = $owner->subscriptions->first();
            $cafe      = $owner->cafes->first();

            return [
                'uuid'         => $owner->uuid,
                'name'         => trim("{$owner->firstname} {$owner->lastname}"),
                'cafe_name'    => $cafe?->cafe_name,
                'email'        => $owner->email,
                'phone_number' => $owner->phone_number,
                'status'       => $owner->status,
                'subscription' => $latestSub?->plan->sub_name,
                'date_joined'  => $owner->created_at?->toISOString(),
            ];
        });
    }

    /**
     * Stats for the top cards.
     */
    public function getStats(): array
    {
        return $this->repo->getStats();
    }

    /**
     * Get full owner profile with cafes, branches, documents, subscription, and payment history.
     */
    /**
     * The owner's applications and branch submissions, newest first. `kind` is
     * application (first main-branch application), resubmission (a main-branch
     * application after an earlier rejection), branch (a side branch) or
     * branch_resubmission (a side branch sent back after it was rejected).
     * `status` is pending_approval | approved | rejected | withdrawn.
     */
    private function applicationHistory(int $userId): array
    {
        $mainRejected   = false;
        $branchRejected = []; // branch_id => true once that side branch was rejected
        $rows = [];

        foreach ($this->repo->approvalHistoryForOwner($userId) as $entry) {
            $isMain = ($entry->branch?->branch_type ?? 'main') === 'main';
            $kind   = $isMain
                ? ($mainRejected ? 'resubmission' : 'application')
                : (isset($branchRejected[$entry->branch_id]) ? 'branch_resubmission' : 'branch');

            if ($entry->status === 'rejected') {
                if ($isMain) {
                    $mainRejected = true;
                } else {
                    $branchRejected[$entry->branch_id] = true;
                }
            }

            $reviewer = $entry->reviewer
                ? trim("{$entry->reviewer->firstname} {$entry->reviewer->lastname}")
                : null;

            $rows[] = [
                'uuid'         => $entry->uuid,
                'kind'         => $kind,
                'status'       => $entry->status, // pending_approval | approved | rejected | withdrawn
                'branch_name'  => $entry->branch?->branch_name,
                'reason'       => $entry->reason,
                'can_resubmit' => (bool) $entry->can_resubmit,
                'submitted_at' => $entry->created_at?->toISOString(),
                'reviewed_at'  => $entry->reviewed_at?->toISOString(),
                'reviewed_by'  => $reviewer ?: null,
            ];
        }

        $rows = array_reverse($rows); // newest first

        // Cafe name changes sit in the same timeline
        foreach ($this->cafeUpdates->historyForOwner($userId)->reverse() as $request) {
            $reviewer = $request->reviewer
                ? trim("{$request->reviewer->firstname} {$request->reviewer->lastname}")
                : null;

            $rows[] = [
                'uuid'         => $request->uuid,
                'kind'         => 'name_change',
                'status'       => $request->status,
                'branch_name'  => "{$request->old_name} → {$request->new_name}",
                'reason'       => $request->reason,
                'can_resubmit' => (bool) $request->can_resubmit,
                'submitted_at' => $request->created_at?->toISOString(),
                'reviewed_at'  => $request->reviewed_at?->toISOString(),
                'reviewed_by'  => $reviewer ?: null,
            ];
        }

        // Merge by date, newest first; the sort is stable, so same-second entries keep the order above
        usort($rows, fn ($a, $b) => strcmp($b['submitted_at'] ?? '', $a['submitted_at'] ?? ''));

        return $rows;
    }

    public function getOwnerDetails(string $uuid): array
    {
        $owner = $this->repo->findOwnerByUuid($uuid);

        $currentSubscription = $owner->subscriptions->firstWhere('status', 'active')
            ?? $owner->subscriptions->first();

        $owner->load('payments.payable.plan');

        return [
            'success' => true,
            'owner'   => new UserResource($owner),

            'owner_documents' => $owner->documents->map(fn ($doc) => [
                'user_doc_id'       => $doc->user_doc_id,
                'id_type'           => $doc->id_type,
                'download_url'      => "/api/documents/user/{$doc->user_doc_id}",
                'download_back_url' => $doc->file_back ? "/api/documents/user/{$doc->user_doc_id}/back" : null,
                'has_back'          => ! empty($doc->file_back),
                'uploaded_at'       => $doc->created_at?->toISOString(),
            ]),

            'cafes' => $owner->cafes->map(function ($cafe) {
                return [
                    'uuid'      => $cafe->uuid,
                    'cafe_name' => $cafe->cafe_name,
                    'documents' => $cafe->documents->map(fn ($doc) => [
                        'cafe_doc_id'   => $doc->cafe_doc_id,
                        'doc_type'      => $doc->doc_type,
                        'download_url'  => "/api/documents/cafe/{$doc->cafe_doc_id}",
                        'registered_at' => $doc->registered_at?->toISOString(),
                    ]),
                    'branches'  => $cafe->branches->map(fn ($branch) => [
                        'uuid'             => $branch->uuid,
                        'branch_name'      => $branch->branch_name,
                        'cafe_picture' => $branch->cafe_picture
                            ? url("/api/branch-picture/{$branch->uuid}")
                            : null,
                        'cafe_email'       => $branch->cafe_email,
                        'cafe_phonenumber' => $branch->cafe_phonenumber,
                        'address'          => $branch->address,
                        'branch_type'      => $branch->branch_type,
                        'status'           => $branch->status,
                        'documents'        => $branch->documents->map(fn ($doc) => [
                            'branch_doc_id' => $doc->branch_doc_id,
                            'doc_type'      => $doc->doc_type,
                            'download_url'  => "/api/documents/branch/{$doc->branch_doc_id}",
                            // BIR registration details (null on other document types)
                            'tin_number'    => $doc->tin_number,
                            'vat'           => $doc->vat, // vat-registered | non-vat
                            'registered_at' => $doc->registered_at?->toISOString(),
                            'expired_at'    => $doc->expired_at?->toISOString(),
                            'uploaded_at'   => ($doc->updated_at ?? $doc->created_at)?->toISOString(),
                        ]),
                    ]),
                ];
            }),

            'subscription' => $currentSubscription ? [
                'uuid'              => $currentSubscription->uuid,
                'status'            => $currentSubscription->status,
                'plan_name'         => $currentSubscription->plan->sub_name ?? null,
                'price'             => $currentSubscription->plan->price ?? null,
                'has_multi_branch'  => $currentSubscription->plan
                    ? $currentSubscription->plan->hasFeature('multi_branch')
                    : false,
                'start_date'     => $currentSubscription->start_date?->toISOString(),
                'end_date'       => $currentSubscription->end_date?->toISOString(),
                'payment_method' => $currentSubscription->latestPayment?->payment_instrument
                    ?? ($currentSubscription->latestPayment?->payment_method_type ? 'PayMongo' : null),
            ] : null,

            'application_history' => $this->applicationHistory($owner->user_id),

            'payment_history' => $owner->payments->sortByDesc('created_at')->map(function ($payment) {
                $rawId = $payment->uuid;
                $cleanId = strtoupper(substr(str_replace('-', '', $rawId), 0, 7));

                $subName = 'Subscription Plan';
                if ($payment->payable && $payment->payable->plan) {
                    $subName = $payment->payable->plan->sub_name;
                }

                return [
                    'transaction_id' => $payment->gateway_transaction_id ?: "TXN-{$cleanId}",
                    'raw_id'         => $rawId,
                    'date'           => $payment->created_at?->toISOString(),
                    'description'    => 'Subscription - ' . $subName,
                    'amount'         => number_format($payment->amount, 2),
                    'status'         => $payment->status,
                    'payment_gateway' => $payment->payment_instrument ?? $payment->payment_method_type ?? 'PayMongo',
                ];
            })->values(),
        ];
    }

    /**
     * Update owner status, cascade to approval_list ONLY for approved/rejected,
     * and send the matching notification email.
     */
    public function updateStatus(string $uuid, string $status, int $reviewerId, ?string $reason = null, bool $allowResubmit = true): array
    {
        $isApplicationDecision = in_array($status, ['approved', 'rejected'], true);

        try {
            $owner = $isApplicationDecision
                ? $this->repo->findPendingApplicantByUuid($uuid)
                : $this->repo->findSaasOwnerForStatusChange($uuid);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::channel('admin')->warning('Owner status update blocked — owner not found or not eligible for this transition.', [
                'owner_uuid' => $uuid,
                'to_status'  => $status,
            ]);

            return [
                'success' => false,
                'message' => "No eligible owner found with UUID '{$uuid}' for status '{$status}'.",
            ];
        }

        $oldStatus = $owner->status;

        $allowedTargets = self::ALLOWED_TRANSITIONS[$oldStatus] ?? [];

        if (! in_array($status, $allowedTargets, true)) {
            Log::channel('admin')->warning('Owner status update rejected — invalid transition.', [
                'owner_uuid'  => $owner->uuid,
                'from_status' => $oldStatus,
                'to_status'   => $status,
            ]);

            return [
                'success' => false,
                'message' => "Cannot change status from '{$oldStatus}' to '{$status}'.",
            ];
        }

        $owner = $this->repo->updateStatus($owner, $status);

        $approval = null;

        if ($isApplicationDecision) {
            $approval = $this->repo->findLatestApproval($owner->user_id);

            if ($approval) {
                $approval = $this->repo->updateApproval($approval, $status, $reviewerId, $reason, $allowResubmit);
            }
        }

        $this->notifications->notifyOwner(
            $owner,
            OwnerNotice::accountStatus($status, $status === 'rejected' ? $reason : null)
        );

        $this->mailer->sendMailable($owner->email, new OwnerStatusMail(
            firstname: $owner->firstname,
            status: $status,
            ownerUuid: $owner->uuid,
            reason: $status === 'rejected' ? $reason : null,
            canResubmit: $status === 'rejected' && $allowResubmit,
        ));

        Log::channel('admin')->info('Owner status updated.', [
            'owner_uuid'  => $owner->uuid,
            'old_status'  => $oldStatus,
            'new_status'  => $status,
            'reason'      => $status === 'rejected' ? $reason : null,
            'can_resubmit' => $status === 'rejected' ? $allowResubmit : null,
            'approval_id' => $approval?->approval_id,
            'reviewed_by' => $reviewerId,
        ]);

        return [
            'success'  => true,
            'message'  => "Owner status updated to '{$status}' and notification email sent.",
            'owner'    => new UserResource($owner),
            'approval' => $approval ? new ApprovalListResource($approval->load(['user', 'cafe', 'branch', 'reviewer'])) : null,
        ];
    }

    public function listApprovals(int $perPage = 15, ?string $status = null, ?string $type = null, ?string $search = null)
    {
        $approvals = $this->repo->listApprovals($perPage, $status, $type, $search);

        return $approvals->through(fn ($approval) => new ApprovalListResource($approval));
    }

    public function getApprovalStats(?string $type = null): array
    {
        return $this->repo->getApprovalStats($type);
    }
    
    /**
     * Review a single branch created *after* the owner is already active
     * (e.g. a side branch). Independent of owner status — does not touch
     * the owner's User.status at all.
     */
    public function updateBranchStatus(string $branchUuid, string $status, int $reviewerId, ?string $reason = null, bool $allowResubmit = true): array
    {
        $branch = $this->repo->findBranchByUuid($branchUuid);

        if (! $branch) {
            return ['success' => false, 'message' => 'Branch not found.'];
        }

        if ($branch->status !== 'pending_approval') {
            Log::channel('admin')->warning('Branch status update rejected — branch not pending.', [
                'branch_uuid'   => $branch->uuid,
                'current_status' => $branch->status,
            ]);

            return [
                'success' => false,
                'message' => "Cannot review a branch with status '{$branch->status}'.",
            ];
        }

        $branchStatus = $status === 'approved' ? 'active' : 'rejected';
        $branch       = $this->repo->updateBranchStatus($branch, $branchStatus);

        $approval = $this->repo->findLatestApprovalForBranch($branch->branch_id);

        if ($approval) {
            $approval = $this->repo->updateApproval($approval, $status, $reviewerId, $reason, $allowResubmit);
        }

        $owner = $branch->cafe->owner ?? null;

        if ($owner) {
            $this->notifications->notifyOwner(
                $owner,
                OwnerNotice::branchStatus($branch->branch_name, $branch->uuid, $status, $status === 'rejected' ? $reason : null)
            );

            $this->mailer->sendMailable(
                $owner->email,
                new BranchStatusMail(
                    firstname: $owner->firstname,
                    branchName: $branch->branch_name,
                    status: $status,
                    reason: $status === 'rejected' ? $reason : null,
                    branchUuid: $branch->uuid,
                    canResubmit: $status !== 'rejected' || $allowResubmit,
                )
            );
        }

        Log::channel('admin')->info('Branch status updated.', [
            'branch_uuid' => $branch->uuid,
            'new_status'  => $branchStatus,
            'reason'      => $status === 'rejected' ? $reason : null,
            'approval_id' => $approval?->approval_id,
            'reviewed_by' => $reviewerId,
        ]);

        return [
            'success'  => true,
            'message'  => "Branch '{$branch->branch_name}' has been {$status}.",
            'branch'   => new CafeBranchResource($branch),
            'approval' => $approval ? new ApprovalListResource($approval->load(['user', 'cafe', 'branch', 'reviewer'])) : null,
        ];
    }

    public function getApplicationHistory(string $uuid): array
    {
        $owner = $this->repo->findOwnerByUuid($uuid); // throws ModelNotFoundException if invalid

        // Same entries as the owner details' application_history (one source of truth).
        return [
            'success' => true,
            'history' => $this->applicationHistory($owner->user_id),
        ];
    }

    /**
     * Full detail snapshot for a single approval row — used by the admin
     * detail modal instead of getOwnerDetails(), so a rejected/archived
     * application always shows its own cafe/branch/documents rather than
     * whatever the owner's current live application happens to be.
     */
    public function getApprovalSnapshot(string $approvalUuid): array
    {
        $approval = $this->repo->findApprovalSnapshot($approvalUuid);

        if (! $approval) {
            return ['success' => false, 'message' => 'Approval record not found.'];
        }

        $owner  = $approval->user;
        $cafe   = $approval->cafe;
        $branch = $approval->branch;

        return [
            'success' => true,

            'owner' => $owner ? new UserResource($owner) : null,

            'owner_documents' => $owner?->documents->map(fn ($doc) => [
                'user_doc_id'       => $doc->user_doc_id,
                'id_type'           => $doc->id_type,
                'download_url'      => "/api/documents/user/{$doc->user_doc_id}",
                'download_back_url' => $doc->file_back ? "/api/documents/user/{$doc->user_doc_id}/back" : null,
                'has_back'          => ! empty($doc->file_back),
                'uploaded_at'       => $doc->created_at?->toISOString(),
            ])->values() ?? [],

            'cafe' => $cafe ? [
                'uuid'        => $cafe->uuid,
                'cafe_name'   => $cafe->cafe_name,
                'is_archived' => $cafe->trashed(),
                'documents'   => $cafe->documents->map(fn ($doc) => [
                    'cafe_doc_id'   => $doc->cafe_doc_id,
                    'doc_type'      => $doc->doc_type,
                    'download_url'  => "/api/documents/cafe/{$doc->cafe_doc_id}",
                    'registered_at' => $doc->registered_at?->toISOString(),
                ])->values(),
            ] : null,

            'branch' => $branch ? [
                'uuid'             => $branch->uuid,
                'branch_name'      => $branch->branch_name,
                'branch_type'      => $branch->branch_type,
                'status'           => $branch->status,
                'is_archived'      => $branch->trashed(),
                'cafe_picture'     => $branch->cafe_picture
                    ? url("/api/branch-picture/{$branch->uuid}")
                    : null,
                'cafe_email'       => $branch->cafe_email,
                'cafe_phonenumber' => $branch->cafe_phonenumber,
                'address'          => $branch->address,
                'documents'        => $branch->documents->map(fn ($doc) => [
                    'branch_doc_id' => $doc->branch_doc_id,
                    'doc_type'      => $doc->doc_type,
                    'download_url'  => "/api/documents/branch/{$doc->branch_doc_id}",
                    'tin_number'    => $doc->tin_number,
                    'vat'           => $doc->vat,
                    'registered_at' => $doc->registered_at?->toISOString(),
                    'expired_at'    => $doc->expired_at?->toISOString(),
                ])->values(),
            ] : null,

            'approval' => [
                'uuid'         => $approval->uuid,
                'status'       => $approval->status,
                'display_status' => $approval->displayStatus(),
                'reason'       => $approval->reason,
                'reviewed_at'  => $approval->reviewed_at?->toISOString(),
                'reviewer'     => $approval->reviewer
                    ? trim("{$approval->reviewer->firstname} {$approval->reviewer->lastname}")
                    : null,
                'submitted_at' => $approval->created_at?->toISOString(),
            ],
        ];
    }
}