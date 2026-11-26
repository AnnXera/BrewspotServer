<?php

namespace App\Services;

use App\Models\BranchDocument;
use App\Models\Cafe;
use App\Models\CafeBranch;
use App\Models\CafeUpdateRequest;
use App\Models\CafeUpdateRequestDocument;
use App\Models\User;
use App\Notifications\CafeUpdateSubmitted;
use App\Notifications\OwnerNotice;
use App\Repository\CafeUpdateRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Changes to registered cafe details, which an admin reviews because they appear on the
 * BIR Certificate of Registration (Form 2303):
 *
 * - Name change: the cafe name is the trade name on every branch's COR, so a rename needs
 *   an updated COR per branch. Until it is approved the old name stays in use everywhere.
 * - Address change: one branch moved, so it sends its new address with the updated COR
 *   showing it. Until it is approved the old address stays.
 *
 * On approval the new details and the BIR document(s) are swapped in one transaction; the
 * old documents are soft-deleted, so they stay on record. A rename waits for no address
 * change to be pending and the reverse, because both replace the branch's COR.
 */
class CafeUpdateService
{
    private const NAME_CHANGE_PENDING = 'Your cafe name change is still being reviewed. Request the address change after it is decided, so the new COR shows the right name.';
    private const ADDRESS_CHANGE_PENDING = 'A branch address change is still being reviewed. Rename the cafe after it is decided, since both replace that branch\'s COR.';

    public function __construct(
        private readonly CafeUpdateRepository $repo,
        private readonly NotificationService $notifications,
        private readonly ActivityLogService $activity
    ) {}

    // ── Owner ────────────────────────────────────────────────────────────

    /** The owner's latest request (any status) and the branches a new one must cover. */
    public function overview(User $owner): array
    {
        $cafe = $owner->cafes()->first();

        if (! $cafe) {
            return ['success' => false, 'message' => 'No cafe found for this account.'];
        }

        $latest = $this->repo->latestForCafe($cafe->cafe_id);

        return [
            'success'   => true,
            'cafe_name' => $cafe->cafe_name,
            'request'   => $latest ? $this->present($latest, forAdmin: false) : null,
            'branches'  => $this->repo->branchesNeedingCor($cafe->cafe_id)->map(fn (CafeBranch $b) => [
                'uuid'                  => $b->uuid,
                'branch_name'           => $b->branch_name,
                'branch_type'           => $b->branch_type,
                'status'                => $b->status,
                'current_registered_at' =>$b->documents->firstWhere('doc_type', 'BIR')?->registered_at?->toDateString(),
            ])->values(),
        ];
    }

    /**
     * @param array{new_name: string, documents: array<string, array{file: UploadedFile, registered_at: string}>} $payload
     *        `documents` is keyed by branch uuid.
     */
    public function submit(User $owner, array $payload): array
    {
        $cafe = $owner->cafes()->first();

        if (! $cafe) {
            return ['success' => false, 'message' => 'No cafe found for this account.'];
        }

        if ($this->repo->hasPendingForCafe($cafe->cafe_id, CafeUpdateRequest::TYPE_NAME_CHANGE)) {
            return ['success' => false, 'message' => 'A name change is already waiting for review. Cancel it first to send a different one.'];
        }

        if ($this->repo->hasPendingForCafe($cafe->cafe_id, CafeUpdateRequest::TYPE_ADDRESS_CHANGE)) {
            return ['success' => false, 'message' => self::ADDRESS_CHANGE_PENDING];
        }

        $newName = trim(preg_replace('/\s+/', ' ', $payload['new_name']));

        if ($newName === $cafe->cafe_name) {
            return [
                'success' => false,
                'message' => 'That is already your cafe name.',
                'errors'  => ['new_name' => ['Enter a name different from the current one.']],
            ];
        }

        $declined = $this->repo->latestForCafe($cafe->cafe_id);
        if ($declined && $declined->status === 'rejected' && ! $declined->can_resubmit
            && mb_strtolower($declined->new_name) === mb_strtolower($newName)) {
            return [
                'success' => false,
                'message' => 'This name was declined and cannot be requested again.',
                'errors'  => ['new_name' => ['This name was declined and cannot be requested again.']],
            ];
        }

        $branches  = $this->repo->branchesNeedingCor($cafe->cafe_id);
        $documents = $payload['documents'] ?? [];
        $errors    = [];

        foreach ($branches as $branch) {
            if (! isset($documents[$branch->uuid]['file'])) {
                $errors["documents.{$branch->uuid}.file"] = ["Upload the updated COR for {$branch->branch_name}."];
            }
        }

        $unknown = array_diff(array_keys($documents), $branches->pluck('uuid')->all());
        foreach ($unknown as $uuid) {
            $errors["documents.{$uuid}"] = ['This branch does not need a COR for this change.'];
        }

        if ($errors) {
            return ['success' => false, 'message' => 'Every branch needs its updated COR.', 'errors' => $errors];
        }

        $request = null;

        try {
            DB::transaction(function () use ($owner, $cafe, $branches, $documents, $newName, &$request) {
                $request = $this->repo->create($cafe, $owner->user_id, $newName);
                $folder  = "users/{$owner->uuid}/cafes/{$cafe->uuid}/name_changes/{$request->uuid}";

                foreach ($branches as $branch) {
                    $doc  = $documents[$branch->uuid];
                    $path = $doc['file']->store("{$folder}/{$branch->uuid}", 'local'); // private

                    $this->repo->addDocument($request, $branch->branch_id, $path, $doc['registered_at']);
                }
            });
        } catch (\Throwable $e) {
            Log::channel('owner')->error('Cafe name change submission failed.', [
                'owner_uuid' => $owner->uuid,
                'error'      => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => 'Something went wrong while sending the name change. Please try again.'];
        }

        $request = $this->repo->findByUuid($request->uuid);

        $this->activity->cafe($owner, $cafe->cafe_id, 'cafe_name_change_requested',
            "Asked to rename the cafe from {$cafe->cafe_name} to {$newName}", ['request_uuid' => $request->uuid]);

        $this->notifications->notifyAdmins(new CafeUpdateSubmitted($owner, $request, $request->isResubmission()));

        Log::channel('owner')->info('Cafe name change submitted.', [
            'owner_uuid'   => $owner->uuid,
            'request_uuid' => $request->uuid,
        ]);

        return [
            'success' => true,
            'message' => 'Name change sent. An admin will review it.',
            'request' => $this->present($request, forAdmin: false),
        ];
    }

    /** An owner cancels their own request while it is still waiting for review. */
    public function withdraw(User $owner, string $uuid): array
    {
        $request = $this->repo->findForOwner($owner->user_id, $uuid);

        if (! $request) {
            return ['success' => false, 'message' => 'Request not found.'];
        }

        if ($request->status !== 'pending_approval') {
            return ['success' => false, 'message' => 'Only a request waiting for review can be cancelled.'];
        }

        $request->update(['status' => 'withdrawn']);

        if ($request->isAddressChange()) {
            if ($request->branch) {
                $this->activity->branch($owner, $request->branch, 'branch_address_change_withdrawn',
                    "Cancelled the request to move {$request->branch->branch_name} to {$request->new_address}");
            }

            return ['success' => true, 'message' => 'Address change cancelled.'];
        }

        $this->activity->cafe($owner, $request->cafe_id, 'cafe_name_change_withdrawn',
            "Cancelled the request to rename the cafe to {$request->new_name}");

        return ['success' => true, 'message' => 'Name change cancelled.'];
    }

    /** Whether the cafe has a name change waiting (adding or resubmitting branches waits for it). */
    public function hasPending(int $cafeId): bool
    {
        return $this->repo->hasPendingForCafe($cafeId, CafeUpdateRequest::TYPE_NAME_CHANGE);
    }

    // ── Owner: branch address change ─────────────────────────────────────

    /**
     * The branch's address, its latest address change (any status), and whether a new one
     * can be sent now (`blocked` holds the reason when it can't).
     */
    public function addressOverview(User $owner, string $branchUuid): array
    {
        $branch = $this->repo->branchForOwner($owner->user_id, $branchUuid);

        if (! $branch) {
            return ['success' => false, 'message' => 'Branch not found.'];
        }

        $latest = $this->repo->latestForBranch($branch->branch_id);

        return [
            'success'               => true,
            'address'               => $branch->address,
            'current_registered_at' => $branch->documents->firstWhere('doc_type', 'BIR')?->registered_at?->toDateString(),
            'request'               => $latest ? $this->present($latest, forAdmin: false) : null,
            'blocked'               => $this->addressChangeBlock($branch),
        ];
    }

    /** @param array{new_address: string, file: UploadedFile, registered_at: string} $payload */
    public function submitAddressChange(User $owner, string $branchUuid, array $payload): array
    {
        $branch = $this->repo->branchForOwner($owner->user_id, $branchUuid);

        if (! $branch) {
            return ['success' => false, 'message' => 'Branch not found.'];
        }

        if ($blocked = $this->addressChangeBlock($branch)) {
            return ['success' => false, 'message' => $blocked];
        }

        $newAddress = trim(preg_replace('/\s+/', ' ', $payload['new_address']));

        if (mb_strtolower($newAddress) === mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $branch->address)))) {
            return [
                'success' => false,
                'message' => 'That is already the branch address.',
                'errors'  => ['new_address' => ['Enter an address different from the current one.']],
            ];
        }

        $declined = $this->repo->latestForBranch($branch->branch_id);
        if ($declined && $declined->status === 'rejected' && ! $declined->can_resubmit
            && mb_strtolower($declined->new_address) === mb_strtolower($newAddress)) {
            return [
                'success' => false,
                'message' => 'This address was declined and cannot be requested again.',
                'errors'  => ['new_address' => ['This address was declined and cannot be requested again.']],
            ];
        }

        $request = null;

        try {
            DB::transaction(function () use ($owner, $branch, $payload, $newAddress, &$request) {
                $request = $this->repo->createAddressChange($branch, $owner->user_id, $newAddress);
                $folder  = "users/{$owner->uuid}/cafes/{$branch->cafe->uuid}/address_changes/{$request->uuid}/{$branch->uuid}";
                $path    = $payload['file']->store($folder, 'local'); // private

                $this->repo->addDocument($request, $branch->branch_id, $path, $payload['registered_at']);
            });
        } catch (\Throwable $e) {
            Log::channel('owner')->error('Branch address change submission failed.', [
                'owner_uuid'  => $owner->uuid,
                'branch_uuid' => $branch->uuid,
                'error'       => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => 'Something went wrong while sending the address change. Please try again.'];
        }

        $request = $this->repo->findByUuid($request->uuid);

        $this->activity->branch($owner, $branch, 'branch_address_change_requested',
            "Asked to move {$branch->branch_name} to {$newAddress}", ['request_uuid' => $request->uuid]);

        $this->notifications->notifyAdmins(new CafeUpdateSubmitted($owner, $request, $request->isResubmission()));

        Log::channel('owner')->info('Branch address change submitted.', [
            'owner_uuid'   => $owner->uuid,
            'request_uuid' => $request->uuid,
        ]);

        return [
            'success' => true,
            'message' => 'Address change sent. An admin will review it.',
            'request' => $this->present($request, forAdmin: false),
        ];
    }

    /** Why the branch can't ask for an address change right now, or null when it can. */
    private function addressChangeBlock(CafeBranch $branch): ?string
    {
        if (! in_array($branch->status, CafeUpdateRepository::BRANCH_STATUSES_FOR_ADDRESS_CHANGE, true)) {
            return 'Only an approved branch can request an address change. Edit a pending or rejected branch by resubmitting it.';
        }

        if ($this->repo->hasPendingAddressChange($branch->branch_id)) {
            return 'An address change for this branch is already waiting for review. Cancel it first to send a different one.';
        }

        if ($this->repo->hasPendingForCafe($branch->cafe_id, CafeUpdateRequest::TYPE_NAME_CHANGE)) {
            return self::NAME_CHANGE_PENDING;
        }

        return null;
    }

    // ── Admin ────────────────────────────────────────────────────────────

    public function list(int $perPage, ?string $status, ?string $search)
    {
        return $this->repo->list($perPage, $status, $search)
            ->through(fn (CafeUpdateRequest $r) => $this->present($r, forAdmin: true));
    }

    public function stats(): array
    {
        return $this->repo->stats();
    }

    public function show(string $uuid): array
    {
        $request = $this->repo->findByUuid($uuid);

        return $request
            ? ['success' => true, 'request' => $this->present($request, forAdmin: true, withCurrentDocs: true)]
            : ['success' => false, 'message' => 'Request not found.'];
    }

    public function review(string $uuid, string $status, User $reviewer, ?string $reason = null, bool $allowResubmit = true): array
    {
        $request = $this->repo->findByUuid($uuid);

        if (! $request) {
            return ['success' => false, 'message' => 'Request not found.'];
        }

        try {
            DB::transaction(function () use ($request, $status, $reviewer, $reason, $allowResubmit) {
                // Re-read under a lock so two admins can't decide the same request
                $locked = CafeUpdateRequest::whereKey($request->request_id)->lockForUpdate()->first();

                if ($locked->status !== 'pending_approval') {
                    throw new \DomainException("This request was already {$locked->status}.");
                }

                if ($status === 'approved') {
                    $request->isAddressChange()
                        ? $this->applyAddressChange($request)
                        : $this->applyNameChange($request);
                }

                $locked->update([
                    'status'       => $status,
                    'reason'       => $status === 'rejected' ? $reason : null,
                    'can_resubmit' => $status === 'rejected' ? $allowResubmit : true,
                    'reviewed_by'  => $reviewer->user_id,
                    'reviewed_at'  => now(),
                ]);
            });
        } catch (\DomainException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            Log::channel('admin')->error('Cafe update review failed.', [
                'request_uuid' => $uuid,
                'error'        => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => 'Something went wrong while saving the review. Please try again.'];
        }

        $request = $this->repo->findByUuid($uuid);

        if ($request->isAddressChange()) {
            return $this->afterAddressReview($request, $status, $reviewer, $reason);
        }

        $this->notifications->notifyOwner($request->owner, OwnerNotice::cafeNameChange(
            $request->old_name, $request->new_name, $status, $status === 'rejected' ? $reason : null
        ));

        $this->activity->cafe($reviewer, $request->cafe_id, "cafe_name_change_{$status}",
            $status === 'approved'
                ? "Approved renaming the cafe from {$request->old_name} to {$request->new_name}"
                : "Declined renaming the cafe to {$request->new_name}",
            array_filter(['request_uuid' => $request->uuid, 'reason' => $status === 'rejected' ? $reason : null]));

        Log::channel('admin')->info('Cafe name change reviewed.', [
            'request_uuid' => $request->uuid,
            'status'       => $status,
            'reviewed_by'  => $reviewer->user_id,
        ]);

        return [
            'success' => true,
            'message' => $status === 'approved'
                ? "The cafe is now called {$request->new_name}."
                : 'The name change was declined.',
            'request' => $this->present($request, forAdmin: true, withCurrentDocs: true),
        ];
    }

    /** Notify, log and answer once an address change is decided. */
    private function afterAddressReview(CafeUpdateRequest $request, string $status, User $reviewer, ?string $reason): array
    {
        $branchName = $request->branch?->branch_name ?? 'The branch';

        $this->notifications->notifyOwner($request->owner, OwnerNotice::branchAddressChange(
            $branchName, $request->branch?->uuid, $request->new_address, $status, $status === 'rejected' ? $reason : null
        ));

        if ($request->branch) {
            $this->activity->branch($reviewer, $request->branch, "branch_address_change_{$status}",
                $status === 'approved'
                    ? "Approved moving {$branchName} to {$request->new_address}"
                    : "Declined moving {$branchName} to {$request->new_address}",
                array_filter(['request_uuid' => $request->uuid, 'reason' => $status === 'rejected' ? $reason : null]));
        }

        Log::channel('admin')->info('Branch address change reviewed.', [
            'request_uuid' => $request->uuid,
            'status'       => $status,
            'reviewed_by'  => $reviewer->user_id,
        ]);

        return [
            'success' => true,
            'message' => $status === 'approved'
                ? "{$branchName} is now at {$request->new_address}."
                : 'The address change was declined.',
            'request' => $this->present($request, forAdmin: true, withCurrentDocs: true),
        ];
    }

    /** Rename the cafe and replace each branch's BIR document with the one sent for it. */
    private function applyNameChange(CafeUpdateRequest $request): void
    {
        Cafe::whereKey($request->cafe_id)->update(['cafe_name' => $request->new_name]);

        foreach ($request->documents as $doc) {
            $branch = $doc->branch;

            // Deleted or rejected since the request was sent: nothing to update
            if (! $branch || $branch->trashed() || $branch->status === 'rejected') {
                continue;
            }

            $this->replaceBir($branch, $doc);
        }
    }

    /** Move the branch to the new address and swap in the COR that shows it. */
    private function applyAddressChange(CafeUpdateRequest $request): void
    {
        $branch = $request->branch;

        if (! $branch || $branch->trashed()) {
            throw new \DomainException('This branch was deleted, so its address can\'t be changed. Decline the request instead.');
        }

        $branch->update(['address' => $request->new_address]);

        if ($doc = $request->documents->first()) {
            $this->replaceBir($branch, $doc);
        }
    }

    /** Soft-delete the branch's BIR document and add the new COR, keeping its TIN, VAT and expiry. */
    private function replaceBir(CafeBranch $branch, CafeUpdateRequestDocument $doc): void
    {
        $current = $this->repo->currentBir($branch->branch_id);

        // Soft-delete first: an active TIN must be unique, and the new row reuses it
        $current?->delete();

        BranchDocument::create([
            'branch_id'     => $branch->branch_id,
            'doc_type'      => 'BIR',
            'file'          => $doc->file,
            'registered_at' => $doc->registered_at,
            'expired_at'    => $current?->expired_at,
            'tin_number'    => $current?->tin_number,
            'vat'           => $current?->vat,
        ]);
    }

    // ── Shape ────────────────────────────────────────────────────────────

    /**
     * Owners never see the reviewing admin's name; admins also get the owner and, on the
     * detail view, each branch's current COR next to the new one.
     */
    private function present(CafeUpdateRequest $r, bool $forAdmin, bool $withCurrentDocs = false): array
    {
        $r->loadMissing(['documents.branch', 'branch', 'owner', 'reviewer', 'cafe']);

        $data = [
            'uuid'         => $r->uuid,
            'type'         => $r->type, // name_change | address_change
            'status'       => $r->displayStatus(), // pending_approval | resubmitted | approved | rejected | withdrawn
            'cafe_name'    => $r->cafe?->cafe_name,
            'old_name'     => $r->old_name, // name_change only
            'new_name'     => $r->new_name,
            'old_address'  => $r->old_address, // address_change only
            'new_address'  => $r->new_address,
            'branch'       => $r->branch ? [
                'uuid'        => $r->branch->uuid,
                'branch_name' => $r->branch->branch_name,
                'branch_type' => $r->branch->branch_type,
                'status'      => $r->branch->trashed() ? 'deleted' : $r->branch->status,
            ] : null,
            'reason'       => $r->reason,
            'can_resubmit' => (bool) $r->can_resubmit,
            'submitted_at' => $r->created_at?->toISOString(),
            'reviewed_at'  => $r->reviewed_at?->toISOString(),
            'documents'    => $r->documents->map(function ($doc) use ($withCurrentDocs, $r) {
                $row = [
                    'id'            => $doc->request_doc_id,
                    'branch_uuid'   => $doc->branch?->uuid,
                    'branch_name'   => $doc->branch?->branch_name,
                    'branch_type'   => $doc->branch?->branch_type,
                    'branch_status' => $doc->branch?->trashed() ? 'deleted' : $doc->branch?->status,
                    'registered_at' => $doc->registered_at?->toDateString(),
                    'file_type'     => strtolower(pathinfo($doc->file, PATHINFO_EXTENSION)),
                    'download_url'  => "/api/documents/cafe-update/{$doc->request_doc_id}",
                ];

                // Before approval: the COR in use now. After: the one it replaced (kept, soft-deleted).
                if ($withCurrentDocs && $doc->branch) {
                    $previous = $r->status === 'approved'
                        ? BranchDocument::withTrashed()->where('branch_id', $doc->branch->branch_id)
                            ->where('doc_type', 'BIR')->where('file', '!=', $doc->file)
                            ->latest('branch_doc_id')->first()
                        : $this->repo->currentBir($doc->branch->branch_id);

                    $row['current_document'] = $previous ? [
                        'id'            => $previous->branch_doc_id,
                        'registered_at' => $previous->registered_at?->toDateString(),
                        'tin_number'    => $previous->tin_number,
                        'file_type'     => strtolower(pathinfo($previous->file, PATHINFO_EXTENSION)),
                        'download_url'  => "/api/documents/branch/{$previous->branch_doc_id}",
                    ] : null;
                }

                return $row;
            })->values(),
        ];

        if ($forAdmin) {
            $data['owner'] = $r->owner ? [
                'uuid'  => $r->owner->uuid,
                'name'  => trim("{$r->owner->firstname} {$r->owner->lastname}"),
                'email' => $r->owner->email,
            ] : null;
            $data['reviewed_by'] = $r->reviewer ? trim("{$r->reviewer->firstname} {$r->reviewer->lastname}") : null;
        }

        return $data;
    }
}
