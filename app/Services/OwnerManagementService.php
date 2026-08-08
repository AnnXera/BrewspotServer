<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

use App\Mail\OwnerStatusMail;
use App\Http\Resources\UserResource;
use App\Http\Resources\ApprovalListResource;
use App\Repository\OwnerManagementRepository;
use Illuminate\Support\Facades\Log;
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
        private readonly MailAdapterInterface $mailer
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
     * Get full owner profile with cafes and branches.
     */
    public function getOwnerDetails(string $uuid): array
    {
        $owner = $this->repo->findOwnerByUuid($uuid);

        $currentSubscription = $owner->subscriptions->firstWhere('status', 'active')
            ?? $owner->subscriptions->first();

        return [
            'success' => true,
            'owner'   => new UserResource($owner),

            'owner_documents' => $owner->documents->map(fn ($doc) => [
                'user_doc_id'  => $doc->user_doc_id,
                'id_type'      => $doc->id_type,
                'download_url' => "/api/documents/user/{$doc->user_doc_id}",
                'uploaded_at'  => $doc->created_at?->toISOString(),
            ]),

            'cafes' => $owner->cafes->map(function ($cafe) {
                return [
                    'uuid'      => $cafe->uuid,
                    'cafe_name' => $cafe->cafe_name,
                    'documents' => $cafe->documents->map(fn ($doc) => [
                        'cafe_doc_id'  => $doc->cafe_doc_id,
                        'doc_type'     => $doc->doc_type,
                        'download_url' => "/api/documents/cafe/{$doc->cafe_doc_id}",
                        'registered_at' => $doc->registered_at?->toISOString(),
                    ]),
                    'branches'  => $cafe->branches->map(fn ($branch) => [
                        'uuid'             => $branch->uuid,
                        'branch_name'      => $branch->branch_name,
                        'cafe_picture'     => $branch->cafe_picture ? Storage::disk('public')->url($branch->cafe_picture) : null,
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

            'subscription' => $currentSubscription ? [
                'uuid'           => $currentSubscription->uuid,
                'status'         => $currentSubscription->status,
                'plan_name'      => $currentSubscription->plan->sub_name ?? null,
                'price'          => $currentSubscription->plan->price ?? null,
                'max_branches'   => $currentSubscription->plan->max_branches ?? null,
                'start_date'     => $currentSubscription->start_date?->toISOString(),
                'end_date'       => $currentSubscription->end_date?->toISOString(),
                'payment_method' => $currentSubscription->latestPayment->payment_method_type ?? null,
            ] : null,

            'payment_history' => $owner->subscriptions->map(function ($sub) {
                $payment = $sub->latestPayment;

                return [
                    'transaction_id' => $payment?->uuid ?? $sub->uuid,
                    'date'           => ($payment?->created_at ?? $sub->created_at)?->toISOString(),
                    'description'    => 'Subscription - ' . ($sub->plan->sub_name ?? 'Plan'),
                    'amount'         => $payment
                        ? number_format($payment->amount / 100, 2)
                        : number_format($sub->plan->price ?? 0, 2),
                    'status'         => $payment?->status ?? $sub->status,
                ];
            })->values(),
        ];
    }

    /**
     * Update owner status, cascade to approval_list ONLY for approved/rejected,
     * and send the matching notification email.
     */
    public function updateStatus(string $uuid, string $status, int $reviewerId): array
    {
        $owner = $this->repo->findOwnerByUuid($uuid);

        if (! $owner) {
            return [
                'success' => false,
                'message' => 'Owner not found.',
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

        // approval_lists scoping rule: only approved/rejected transitions touch it.
        // suspend/reinstate must never overwrite the original application review record.
        $approval = null;

        if (in_array($status, ['approved', 'rejected'], true)) {
            $approval = $this->repo->findLatestApproval($owner->user_id);

            if ($approval) {
                $approval = $this->repo->updateApproval($approval, $status, $reviewerId);
            }
        }

        $this->mailer->sendMailable($owner->email, new OwnerStatusMail($owner->firstname, $status, $owner->uuid));

        Log::channel('admin')->info('Owner status updated.', [
            'owner_uuid'  => $owner->uuid,
            'old_status'  => $oldStatus,
            'new_status'  => $status,
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

    /**
     * List all approval entries for admin review screen.
     */
    public function listApprovals(int $perPage = 15, ?string $status = null)
    {
        $approvals = $this->repo->listApprovals($perPage, $status);

        return $approvals->through(fn ($approval) => new ApprovalListResource($approval));
    }
}