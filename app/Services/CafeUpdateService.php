<?php

namespace App\Services;

use App\Models\BranchDocument;
use App\Models\Cafe;
use App\Models\CafeBranch;
use App\Models\CafeUpdateRequest;
use App\Models\User;
use App\Notifications\CafeUpdateSubmitted;
use App\Notifications\OwnerNotice;
use App\Repository\CafeUpdateRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cafe name changes. The cafe name is the trade name on every branch's BIR
 * Certificate of Registration, so a rename needs an updated COR per branch and an
 * admin's approval. Until then the old name stays in use everywhere (receipts too).
 * On approval the name and every branch's BIR document are swapped in one
 * transaction; the old documents are soft-deleted, so they stay on record.
 */
class CafeUpdateService
{
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

        if ($this->repo->hasPendingForCafe($cafe->cafe_id)) {
            return ['success' => false, 'message' => 'A name change is already waiting for review. Cancel it first to send a different one.'];
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

        $this->activity->cafe($owner, $request->cafe_id, 'cafe_name_change_withdrawn',
            "Cancelled the request to rename the cafe to {$request->new_name}");

        return ['success' => true, 'message' => 'Name change cancelled.'];
    }

    /** Whether the cafe has a name change waiting (adding or resubmitting branches waits for it). */
    public function hasPending(int $cafeId): bool
    {
        return $this->repo->hasPendingForCafe($cafeId);
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
                    $this->applyNameChange($request);
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
            Log::channel('admin')->error('Cafe name change review failed.', [
                'request_uuid' => $uuid,
                'error'        => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => 'Something went wrong while saving the review. Please try again.'];
        }

        $request = $this->repo->findByUuid($uuid);

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
    }

    // ── Shape ────────────────────────────────────────────────────────────

    /**
     * Owners never see the reviewing admin's name; admins also get the owner and, on the
     * detail view, each branch's current COR next to the new one.
     */
    private function present(CafeUpdateRequest $r, bool $forAdmin, bool $withCurrentDocs = false): array
    {
        $r->loadMissing(['documents.branch', 'owner', 'reviewer', 'cafe']);

        $data = [
            'uuid'         => $r->uuid,
            'type'         => $r->type,
            'status'       => $r->displayStatus(), // pending_approval | resubmitted | approved | rejected | withdrawn
            'old_name'     => $r->old_name,
            'new_name'     => $r->new_name,
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
