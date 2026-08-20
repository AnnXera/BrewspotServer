<?php

namespace App\Services;

use App\Http\Resources\CafeBranchResource;
use App\Models\User;

use App\Repository\BranchRepository;
use App\Repository\SubscriptionRepository;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BranchService
{
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

        $maxBranches     = $subscription->plan->max_branches;
        $existingBranches = $this->repo->countExistingBranches($cafe->cafe_id);

        if ($existingBranches >= $maxBranches) {
            Log::channel('owner')->warning('Branch creation blocked — max branches reached.', [
                'owner_uuid'        => $owner->uuid,
                'plan'              => $subscription->plan->sub_name,
                'max_branches'      => $maxBranches,
                'existing_branches' => $existingBranches,
            ]);

            return [
                'success' => false,
                'message' => "You've reached the maximum of {$maxBranches} branch(es) allowed under your current plan ({$subscription->plan->sub_name}). Upgrade your plan to add more branches.",
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
     * @param string $disk 'local' (private, default) or 'public'
     */
    private function storeFile(UploadedFile $file, string $path, string $disk = 'local'): string
    {
        return $file->store($path, $disk);
    }
}